<?php

declare(strict_types=1);

use Hypervel\Grpc\Health\HealthService;
use Hypervel\Support\Facades\Grpc;
use Hypervel\Tests\Integration\Grpc\Fixtures\TestService;

Grpc::service('grpc.health.v1.Health', function (): void {
    Grpc::unary('Check', [HealthService::class, 'check']);
    Grpc::unary('List', [HealthService::class, 'list']);
    Grpc::serverStream('Watch', [HealthService::class, 'watch']);
});

Grpc::service('hypervel.grpc.testing.TestService', function (): void {
    Grpc::unary('Unary', [TestService::class, 'unary']);
    Grpc::serverStream('ServerStream', [TestService::class, 'serverStream']);
});
