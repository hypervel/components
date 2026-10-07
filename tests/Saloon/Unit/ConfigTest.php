<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Http\Client\StrayRequestException;
use Hypervel\Saloon\Facades\Saloon;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\Http\PendingRequest;
use Hypervel\Saloon\Http\Response;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// Hypervel has no process-global Saloon configuration. Global middleware belongs to the Saloon manager, and requests
// are sent through named Hypervel HTTP connections rather than configurable senders.
class ConfigTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testTheConfigCanSpecifyGlobalMiddleware(): void
    {
        $mockClient = new MockClient([
            new MockResponse(['name' => 'Jake Owen - Beachin']),
        ]);

        $count = 0;

        Saloon::middleware()->onRequest(function (PendingRequest $pendingRequest) use (&$count): void {
            ++$count;
        });

        Saloon::middleware()->onResponse(function (Response $response) use (&$count): void {
            ++$count;
        });

        TestConnector::make()->send(new UserRequest, $mockClient);

        $this->assertSame(2, $count);
    }

    // Upstream swaps the default sender class. Saloon sends through the configured HTTP connection instead.
    public function testYouCanChangeTheGlobalDefaultConnectionUsed(): void
    {
        Http::registerConnection('secondary', ['timeout' => 12]);
        $timeouts = [];
        Http::fake(function (HttpRequest $request, array $options) use (&$timeouts): PromiseInterface {
            $timeouts[] = $options['timeout'];

            return Http::response();
        });

        config(['saloon.connection.name' => 'secondary']);
        TestConnector::make()->send(new UserRequest);

        config(['saloon.connection.name' => 'saloon']);
        TestConnector::make()->send(new UserRequest);

        $this->assertSame([12, 30], $timeouts);
    }

    // REMOVED: Config::setSenderResolver(). A connector selects another registered HTTP connection through
    // resolveHttpConnection(); see Unit/SenderTest.

    // REMOVED: Config::setClock(), getClock() and now(). Saloon reads the framework clock, which tests control with
    // Date::setTestNow().

    public function testYouCanPreventStrayApiRequests(): void
    {
        Http::preventStrayRequests();

        $this->expectException(StrayRequestException::class);
        $this->expectExceptionMessageIs('Attempted request to [https://tests.saloon.dev/api/user] without a matching fake.');

        TestConnector::make()->send(new UserRequest);
    }

    // Allowing stray requests again sends a real request, so that case runs against the engine test server in
    // tests/Integration/Saloon/Unit/ConfigTest.

    // REMOVED: Config::sleepUsing() and sleep(). Saloon sleeps through the framework Sleep class, which tests fake with
    // Sleep::fake(); see the delay and retry tests.
}
