<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Grpc\Fixtures;

use Hypervel\Grpc\Health\HealthStatusProvider;
use Hypervel\Grpc\Health\ServingStatus;

class TestHealthStatusProvider implements HealthStatusProvider
{
    /**
     * Return the configured test service status.
     */
    public function statusFor(string $service): ?ServingStatus
    {
        return match ($service) {
            '' => ServingStatus::Serving,
            'testing' => ServingStatus::NotServing,
            default => null,
        };
    }

    /**
     * Return every configured test service status.
     *
     * @return array<string, ServingStatus>
     */
    public function statuses(): array
    {
        return [
            '' => ServingStatus::Serving,
            'testing' => ServingStatus::NotServing,
        ];
    }
}
