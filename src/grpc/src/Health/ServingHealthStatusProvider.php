<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Health;

class ServingHealthStatusProvider implements HealthStatusProvider
{
    /**
     * Return the health status for a service.
     */
    public function statusFor(string $service): ?ServingStatus
    {
        return $service === '' ? ServingStatus::Serving : null;
    }

    /**
     * Return every known service health status.
     *
     * @return array<string, ServingStatus>
     */
    public function statuses(): array
    {
        return ['' => ServingStatus::Serving];
    }
}
