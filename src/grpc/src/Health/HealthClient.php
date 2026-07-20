<?php

declare(strict_types=1);

namespace Hypervel\Grpc\Health;

use Hypervel\Grpc\Client\BaseClient;
use Hypervel\Grpc\Client\ServerStreamingCall;
use Hypervel\Grpc\Client\UnaryCall;
use Hypervel\Grpc\Health\V1\HealthCheckRequest;
use Hypervel\Grpc\Health\V1\HealthCheckResponse;
use Hypervel\Grpc\Health\V1\HealthListRequest;
use Hypervel\Grpc\Health\V1\HealthListResponse;
use Hypervel\Grpc\Metadata;

class HealthClient extends BaseClient
{
    /**
     * Check the health of one service.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function check(
        HealthCheckRequest $request,
        array|Metadata $metadata = [],
        array $options = [],
    ): UnaryCall {
        return $this->_simpleRequest(
            '/grpc.health.v1.Health/Check',
            $request,
            [HealthCheckResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }

    /**
     * List every known service health status.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function list(
        HealthListRequest $request,
        array|Metadata $metadata = [],
        array $options = [],
    ): UnaryCall {
        return $this->_simpleRequest(
            '/grpc.health.v1.Health/List',
            $request,
            [HealthListResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }

    /**
     * Watch health status changes for one service.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function watch(
        HealthCheckRequest $request,
        array|Metadata $metadata = [],
        array $options = [],
    ): ServerStreamingCall {
        return $this->_serverStreamRequest(
            '/grpc.health.v1.Health/Watch',
            $request,
            [HealthCheckResponse::class, 'decode'],
            $metadata,
            $options,
        );
    }
}
