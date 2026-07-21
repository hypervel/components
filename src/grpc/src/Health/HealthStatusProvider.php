<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Health;

interface HealthStatusProvider
{
    /**
     * Return the health status for a service.
     */
    public function statusFor(string $service): ?ServingStatus;

    /**
     * Return every known service health status.
     *
     * @return array<string, ServingStatus>
     */
    public function statuses(): array;
}
