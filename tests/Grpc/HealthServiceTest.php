<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Health\HealthService;
use Hypervel\Grpc\Health\HealthStatusProvider;
use Hypervel\Grpc\Health\ServingHealthStatusProvider;
use Hypervel\Grpc\Health\ServingStatus;
use Hypervel\Grpc\Health\V1\HealthCheckRequest;
use Hypervel\Grpc\Health\V1\HealthCheckResponse\ServingStatus as WireServingStatus;
use Hypervel\Grpc\Health\V1\HealthListRequest;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\TestCase;

class HealthServiceTest extends TestCase
{
    public function testDefaultProviderReportsOnlyTheWholeServerAsServing(): void
    {
        $service = new HealthService(new ServingHealthStatusProvider);

        $response = $service->check(new HealthCheckRequest);

        $this->assertSame(WireServingStatus::SERVING, $response->getStatus());

        try {
            $service->check((new HealthCheckRequest)->setService('example.Greeter'));
            $this->fail('An unknown service should fail the health check.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::NotFound, $exception->status()->code());
            $this->assertSame('The requested service is unknown.', $exception->getMessage());
        }
    }

    public function testUsesTheApplicationHealthProviderForCheckAndList(): void
    {
        $provider = new MutableHealthStatusProvider([
            'unknown.Service' => ServingStatus::Unknown,
            'serving.Service' => ServingStatus::Serving,
            'stopped.Service' => ServingStatus::NotServing,
        ]);
        $service = new HealthService($provider);

        $this->assertSame(
            WireServingStatus::UNKNOWN,
            $service->check((new HealthCheckRequest)->setService('unknown.Service'))->getStatus(),
        );
        $this->assertSame(
            WireServingStatus::SERVING,
            $service->check((new HealthCheckRequest)->setService('serving.Service'))->getStatus(),
        );
        $this->assertSame(
            WireServingStatus::NOT_SERVING,
            $service->check((new HealthCheckRequest)->setService('stopped.Service'))->getStatus(),
        );

        $statuses = $service->list(new HealthListRequest)->getStatuses();

        $this->assertSame(
            WireServingStatus::UNKNOWN,
            $statuses['unknown.Service']->getStatus(),
        );
        $this->assertSame(
            WireServingStatus::SERVING,
            $statuses['serving.Service']->getStatus(),
        );
        $this->assertSame(
            WireServingStatus::NOT_SERVING,
            $statuses['stopped.Service']->getStatus(),
        );
    }

    public function testProviderStatusIsResolvedForEveryCall(): void
    {
        $provider = new MutableHealthStatusProvider(['' => ServingStatus::Serving]);
        $service = new HealthService($provider);

        $this->assertSame(
            WireServingStatus::SERVING,
            $service->check(new HealthCheckRequest)->getStatus(),
        );

        $provider->statuses = ['' => ServingStatus::NotServing];

        $this->assertSame(
            WireServingStatus::NOT_SERVING,
            $service->check(new HealthCheckRequest)->getStatus(),
        );
    }

    public function testProviderEnumMatchesTheReusableWireStatuses(): void
    {
        $this->assertSame(WireServingStatus::UNKNOWN, ServingStatus::Unknown->value);
        $this->assertSame(WireServingStatus::SERVING, ServingStatus::Serving->value);
        $this->assertSame(WireServingStatus::NOT_SERVING, ServingStatus::NotServing->value);
        $this->assertSame(3, WireServingStatus::SERVICE_UNKNOWN);
    }

    public function testWatchReturnsTheProtocolDefinedUnimplementedFallback(): void
    {
        $service = new HealthService(new ServingHealthStatusProvider);

        try {
            $service->watch(new HealthCheckRequest);
            $this->fail('Health watching should be unavailable on this server.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::Unimplemented, $exception->status()->code());
            $this->assertSame(
                'Health status watching is not supported by this server.',
                $exception->getMessage(),
            );
        }
    }
}

class MutableHealthStatusProvider implements HealthStatusProvider
{
    /** @param array<string, ServingStatus> $statuses */
    public function __construct(public array $statuses)
    {
    }

    public function statusFor(string $service): ?ServingStatus
    {
        return $this->statuses[$service] ?? null;
    }

    public function statuses(): array
    {
        return $this->statuses;
    }
}
