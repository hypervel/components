<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Health;

use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Health\V1\HealthCheckRequest;
use Hypervel\Grpc\Health\V1\HealthCheckResponse;
use Hypervel\Grpc\Health\V1\HealthListRequest;
use Hypervel\Grpc\Health\V1\HealthListResponse;
use Hypervel\Grpc\StatusCode;

readonly class HealthService
{
    public function __construct(private HealthStatusProvider $health)
    {
    }

    /**
     * Return the health status for one service.
     */
    public function check(HealthCheckRequest $request): HealthCheckResponse
    {
        $status = $this->health->statusFor($request->getService());

        if ($status === null) {
            throw new RpcException(StatusCode::NotFound, 'The requested service is unknown.');
        }

        return (new HealthCheckResponse)->setStatus($status->value);
    }

    /**
     * Return the health status for every known service.
     */
    public function list(HealthListRequest $request): HealthListResponse
    {
        $statuses = [];

        foreach ($this->health->statuses() as $service => $status) {
            $statuses[$service] = (new HealthCheckResponse)->setStatus($status->value);
        }

        return (new HealthListResponse)->setStatuses($statuses);
    }

    /**
     * Stream health status changes for one service.
     *
     * @return iterable<HealthCheckResponse>
     */
    public function watch(HealthCheckRequest $request): iterable
    {
        throw new RpcException(
            StatusCode::Unimplemented,
            'Health status watching is not supported by this server.',
        );
    }
}
