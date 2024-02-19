<?php

namespace ValeSaude\TelemedicineClient\Providers;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use ValeSaude\LaravelValueObjects\FullName;
use ValeSaude\TelemedicineClient\Collections\AppointmentSlotCollection;
use ValeSaude\TelemedicineClient\Collections\DoctorCollection;
use ValeSaude\TelemedicineClient\Concerns\HasCacheHandlerTrait;
use ValeSaude\TelemedicineClient\Contracts\AuthenticatesUsingPatientDataInterface;
use ValeSaude\TelemedicineClient\Contracts\FleuryConfigRepositoryInterface;
use ValeSaude\TelemedicineClient\Contracts\ProviderErrorHandlerInterface;
use ValeSaude\TelemedicineClient\Contracts\ScheduledTelemedicineProviderInterface;
use ValeSaude\TelemedicineClient\Contracts\SchedulesUsingPatientData;
use ValeSaude\TelemedicineClient\Contracts\SharedConfigRepositoryInterface;
use ValeSaude\TelemedicineClient\Data\PatientData;
use ValeSaude\TelemedicineClient\Entities\Appointment;
use ValeSaude\TelemedicineClient\Entities\AppointmentSlot;
use ValeSaude\TelemedicineClient\Entities\Doctor;
use ValeSaude\TelemedicineClient\Helpers\FleuryAttributeConverter;

class FleuryScheduledTelemedicineProvider implements ScheduledTelemedicineProviderInterface, AuthenticatesUsingPatientDataInterface, SchedulesUsingPatientData
{
    use HasCacheHandlerTrait;

    private string $baseUrl;
    private string $apiKey;
    private string $clientId;
    private ?PatientData $authPatientData = null;
    private ?string $authToken = null;
    private ProviderErrorHandlerInterface $errorHandler;

    public function __construct(
        SharedConfigRepositoryInterface $sharedConfig,
        FleuryConfigRepositoryInterface $providerConfig,
        ProviderErrorHandlerInterface $errorHandler
    ) {
        $this->baseUrl = $providerConfig->getBaseUrl();
        $this->apiKey = $providerConfig->getApiKey();
        $this->clientId = $providerConfig->getClientId();
        $this->cache = $sharedConfig->getCache();
        $this->errorHandler = $errorHandler;
    }

    public function setPatientDataForAuthentication(PatientData $data): self
    {
        $this->authPatientData = $data;

        return $this;
    }

    public function authenticate(): string
    {
        $patientData = $this->authPatientData;

        if (!$patientData) {
            throw new RuntimeException('The patient data is not set.');
        }

        $response = $this
            ->newRequest(false)
            ->post('integration/cuidado-digital/v1/autenticate', [
                'apiKey' => $this->apiKey,
                'client' => $this->clientId,
                'name' => (string) $patientData->getName(),
                'documentNumber' => $patientData->getDocument()->getNumber(),
                'gender' => (string) $patientData->getGender(),
                'birth' => $patientData->getBirthDate()->format('Y-m-d'),
                'phone' => (string) $patientData->getPhone(),
                'email' => (string) $patientData->getEmail(),
            ])
            ->onError(fn (Response $response) => $this->errorHandler->handleErrors($response));

        $this->authToken = $response->json('access_token');

        return $this->authToken;
    }

    public function getDoctors(?string $specialty = null, ?string $name = null): DoctorCollection
    {
        $payload = [];

        if ($specialty) {
            $payload['specialty'] = $specialty;
        }

        if ($name) {
            $payload['name'] = $name;
        }

        $cacheKey = $this->generateCacheKeyFromArguments(
            'scheduled.telemedicine.providers:fleury:doctors',
            $payload
        );

        /** @var array<array<string, mixed>> $response */
        $response = $this->handlePossiblyCachedCall(
            $cacheKey,
            function () use ($payload) {
                $this->ensureIsAuthenticated();

                return $this
                    ->newRequest()
                    ->get('integration/cuidado-digital/v1/profissionais', $payload)
                    ->onError(fn (Response $response) => $this->errorHandler->handleErrors($response))
                    ->json();
            }
        );
        $doctors = new DoctorCollection();

        foreach ($response as $doctorData) {
            $doctors->add($this->createDoctorFromDoctorData($doctorData));
        }

        return $doctors;
    }

    public function getSlotsForDoctor(
        string $doctorId,
        ?string $specialty = null,
        ?CarbonInterface $until = null,
        ?int $limit = null
    ): AppointmentSlotCollection {
        $this->ensureIsAuthenticated();

        $doctors = $this->getDoctorsWithSlots($specialty, $doctorId, $until, $limit);

        if ($doctors->isEmpty()) {
            return new AppointmentSlotCollection();
        }

        return $doctors->at(0)->getSlots();
    }

    public function getDoctorsWithSlots(
        ?string $specialty = null,
        ?string $doctorId = null,
        ?CarbonInterface $until = null,
        ?int $slotLimit = null
    ): DoctorCollection {
        $payload = [
            'date_init' => today()->format('Y-m-d'),
            'type' => 'REMOTE',
            'appointment_type' => 'DOCTOR_FAMILY',
            'limitForProfessional' => 50,
        ];

        if ($specialty) {
            $payload['specialty'] = $specialty;
        }

        if ($doctorId) {
            $payload['professional_id'] = $doctorId;
        }

        if ($until) {
            $payload['date_end'] = $until->format('Y-m-d');
        }

        if ($slotLimit) {
            $payload['limitForProfessional'] = min($slotLimit, 50);
        }

        $cacheKey = $this->generateCacheKeyFromArguments(
            'scheduled.telemedicine.providers:fleury:doctors-with-slots',
            $payload
        );
        /** @var array<array<string, mixed>> $response */
        $response = $this->handlePossiblyCachedCall(
            $cacheKey,
            function () use ($payload) {
                $this->ensureIsAuthenticated();

                return $this
                    ->newRequest()
                    ->get('integration/cuidado-digital/v1/horarios-por-profissional', $payload)
                    ->onError(fn (Response $response) => $this->errorHandler->handleErrors($response))
                    ->json();
            }
        );

        $collection = new DoctorCollection();

        foreach ($response as $doctorAndSlotData) {
            if (empty($doctorAndSlotData['slots'])) {
                continue;
            }

            $slots = new AppointmentSlotCollection();

            foreach ($doctorAndSlotData['slots'] as $slot) {
                $slots->add(
                    new AppointmentSlot(
                        data_get($slot, 'id'),
                        FleuryAttributeConverter::convertProviderDateToCarbon(data_get($slot, 'date'))
                    )
                );
            }

            $collection->add(
                $this->createDoctorFromDoctorData(
                    data_get($doctorAndSlotData, 'professional'),
                    $slots
                )
            );
        }

        return $collection;
    }

    public function scheduleUsingPatientData(string $specialty, string $slotId, PatientData $patientData): Appointment
    {
        $this->ensureIsAuthenticated();

        $response = $this
            ->newRequest()
            ->post('integration/cuidado-digital/v1/consultas', [
                'slot_id' => $slotId,
                'patient' => [
                    'name' => (string) $patientData->getName(),
                    'national_id' => $patientData->getDocument()->getNumber(),
                    'gender' => (string) $patientData->getGender(),
                    'dob' => $patientData->getBirthDate()->format('Y-m-d'),
                    'cellphone' => (string) $patientData->getPhone(),
                    'email' => (string) $patientData->getEmail(),
                ],
            ])
            ->onError(fn (Response $response) => $this->errorHandler->handleErrors($response));

        return new Appointment(
            $response->json('id'),
            FleuryAttributeConverter::convertProviderDateToCarbon($response->json('date')),
            FleuryAttributeConverter::convertProviderAppointmentStatusToLocal($response->json('status')),
        );
    }

    public function getAppointmentLink(string $appointmentId): string
    {
        $this->ensureIsAuthenticated();

        $response = $this
            ->newRequest()
            ->get("integration/cuidado-digital/v1/consultas/{$appointmentId}")
            ->onError(fn (Response $response) => $this->errorHandler->handleErrors($response));

        return $response->json('attendance_link');
    }

    private function newRequest(bool $withToken = true): PendingRequest
    {
        $request = Http::baseUrl($this->baseUrl)->asJson();

        if ($withToken) {
            $request->withHeaders(['x-authorization-token' => $this->authToken]);
        }

        return $request;
    }

    private function ensureIsAuthenticated(): void
    {
        if (!$this->authToken) {
            $this->authenticate();
        }
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     council: string,
     *     avatar: string|null,
     *  } $data
     */
    private function createDoctorFromDoctorData(array $data, ?AppointmentSlotCollection $slots = null): Doctor
    {
        return new Doctor(
            $data['id'],
            FullName::fromFullNameString($data['name']),
            $data['council'],
            $data['avatar'] ?: null,
            $slots
        );
    }
}