<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use DateInterval;
use DateTimeInterface;
use Exception;
use Hypervel\Contracts\Cache\Factory as CacheFactory;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Factory;
use Hypervel\RateLimiter\RateLimiter;
use Hypervel\Saloon\Cache\Contracts\Cacheable;
use Hypervel\Saloon\Cache\Traits\HasCaching;
use Hypervel\Saloon\Enums\Method;
use Hypervel\Saloon\Http\Connector;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Request;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Saloon\Traits\RequestProperties\HasDelay;
use Hypervel\Support\Facades\Http;
use Hypervel\Support\Sleep;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;
use InvalidArgumentException;
use Mockery as m;

// REMOVED: upstream Unit/Body/IntegerBodyRepositoryTest. Delays are nullable integers, so there is no
// public integer store; the empty, default, set, and zero cases below cover the same delay semantics.
// Upstream waits in real time; these cases assert the delay with Sleep::fake(). Sends that upstream makes to its live
// API use Http::fake(), which keeps Saloon's transport path.
class DelayRequestTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    // REMOVED: async request delay works. sendAsync() is replaced by connector pools.

    public function testRequestDelayTakesPriorityOverConnectorDelay(): void
    {
        Sleep::fake();

        $request = (new UserRequest)->delay(100);

        $this->assertSame(100, $request->delayMilliseconds());

        (new TestConnector)->send($request, new MockClient([MockResponse::make(['name' => 'Sam'])]));
        (new DelayConnectorStub)->send($request, new MockClient([MockResponse::make(['name' => 'Sam'])]));

        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(),
            Sleep::for(100)->milliseconds(),
        ]);
    }

    // Upstream's custom sleep handler case is the same assertion: Sleep::fake() records the delay instead of waiting.
    public function testRequestDelayWorks(): void
    {
        Sleep::fake();
        Http::fake(['*' => Factory::response(['name' => 'Sam'])]);

        $request = (new UserRequest)->delay(1000);

        $this->assertSame(1000, $request->delayMilliseconds());

        (new TestConnector)->send($request);
        (new TestConnector)->send($request, new MockClient([MockResponse::make(['name' => 'Sam'])]));

        Sleep::assertSequence([
            Sleep::for(1000)->milliseconds(),
            Sleep::for(1000)->milliseconds(),
        ]);
    }

    public function testConnectorDelayWorks(): void
    {
        Sleep::fake();
        Http::fake(['*' => Factory::response(['name' => 'Sam'])]);

        $request = new UserRequest;
        $connector = new DelayConnectorStub;

        $this->assertNull($request->delayMilliseconds());
        $this->assertSame(500, $connector->delayMilliseconds());

        $connector->send($request, new MockClient([MockResponse::make(['name' => 'Sam'])]));
        $connector->send($request);

        Sleep::assertSequence([
            Sleep::for(500)->milliseconds(),
            Sleep::for(500)->milliseconds(),
        ]);
    }

    public function testTheDelayRunsBeforeAFakeResponseThatThrows(): void
    {
        Sleep::fake();
        $exception = new Exception('The fake failed.');

        try {
            (new TestConnector)->send(
                (new UserRequest)->delay(100),
                new MockClient([MockResponse::make()->throw($exception)]),
            );

            $this->fail('The request did not throw.');
        } catch (Exception $caught) {
            $this->assertSame($exception, $caught);
        }

        Sleep::assertSequence([Sleep::for(100)->milliseconds()]);
    }

    public function testTheDelayRunsBeforeEveryAttemptSeparatelyFromTheRetryInterval(): void
    {
        Sleep::fake();

        (new TestConnector)->send((new UserRequest)->delay(100)->retry(2, 300), new MockClient([
            MockResponse::make(['name' => 'Sam'], 500),
            MockResponse::make(['name' => 'Gareth']),
        ]));

        Sleep::assertSequence([
            Sleep::for(100)->milliseconds(),
            Sleep::for(300)->milliseconds(),
            Sleep::for(100)->milliseconds(),
        ]);
    }

    public function testCacheHitsAreNotDelayed(): void
    {
        Sleep::fake();
        Http::fake(['*' => Factory::response(['name' => 'Sam'])]);

        $first = (new TestConnector)->send((new CachedDelayRequestStub)->delay(100));
        $second = (new TestConnector)->send((new CachedDelayRequestStub)->delay(100));

        $this->assertFalse($first->isCached());
        $this->assertTrue($second->isCached());
        Sleep::assertSequence([Sleep::for(100)->milliseconds()]);
    }

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

class CachedDelayRequestStub extends UserRequest implements Cacheable
{
    use HasCaching;

    /**
     * Get the cache lifetime.
     */
    public function cacheFor(): DateInterval|DateTimeInterface|int
    {
        return 60;
    }
}
