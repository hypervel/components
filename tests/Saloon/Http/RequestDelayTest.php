<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\Traits\RequestProperties\HasDelay;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;

// REMOVED: upstream Unit/Body/IntegerBodyRepositoryTest. Delays are nullable integers, so there is no
// public integer store; the empty, default, set, and zero cases below cover the same delay semantics.
class RequestDelayTest extends TestCase
{
    public function testTheDelayIsEmptyByDefault(): void
    {
        $this->assertNull($this->request()->delayMilliseconds());
    }

    public function testItStoresARepresentableDelay(): void
    {
        $request = $this->request()->delay(250);

        $this->assertSame(250, $request->delayMilliseconds());
    }

    public function testTheDefaultDelayIsUsedUntilADelayIsSet(): void
    {
        $request = new DefaultDelayRequestStub;

        $this->assertSame(100, $request->delayMilliseconds());
        $this->assertSame(0, $request->delay(0)->delayMilliseconds());
    }

    public function testAnExplicitZeroDelayOverridesTheConnectorDelay(): void
    {
        $this->assertSame(500, $this->pendingRequest(new DelayRequestStub)->delayMilliseconds());
        $this->assertSame(0, $this->pendingRequest((new DelayRequestStub)->delay(0))->delayMilliseconds());
    }

    public function testItRejectsNegativeDelays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('representable non-negative');

        $this->request()->delay(-1);
    }

    public function testItRejectsDelaysThatOverflowMicroseconds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('representable non-negative');

        $this->request()->delay(intdiv(PHP_INT_MAX, 1000) + 1);
    }

    /**
     * Create an object with an operation-owned request delay.
     */
    protected function request(): object
    {
        return new class {
            use HasDelay;
        };
    }

    /**
     * Create a pending request through a connector with a default delay.
     */
    protected function pendingRequest(Request $request): PendingRequest
    {
        return new PendingRequest(
            new DelayConnectorStub,
            $request,
            m::mock(CacheFactory::class),
            m::mock(RateLimiter::class),
        );
    }
}

class DelayConnectorStub extends Connector
{
    /**
     * Resolve the integration base URL.
     */
    public function resolveBaseUrl(): string
    {
        return 'https://api.example.com';
    }

    /**
     * Resolve the default request delay in milliseconds.
     */
    protected function defaultDelay(): ?int
    {
        return 500;
    }
}

class DelayRequestStub extends Request
{
    protected Method $method = Method::GET;

    /**
     * Resolve the request endpoint.
     */
    public function resolveEndpoint(): string
    {
        return '/users';
    }
}

class DefaultDelayRequestStub extends DelayRequestStub
{
    /**
     * Resolve the default request delay in milliseconds.
     */
    protected function defaultDelay(): ?int
    {
        return 100;
    }
}
