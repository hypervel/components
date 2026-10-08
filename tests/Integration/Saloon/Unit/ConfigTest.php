<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Saloon\Unit;

use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithServer;
use Hypervel\Http\Client\StrayRequestException;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

// Upstream's live test API is replaced by the engine test server's /user route.
class ConfigTest extends TestCase
{
    use InteractsWithServer;

    protected int $serverPort = 19505;

    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    /**
     * Set up the test server connection.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->setUpInteractsWithServer();
    }

    public function testYouCanPreventAndThenAllowStrayApiRequests(): void
    {
        $connector = new TestConnector($this->serverUrl());

        Http::preventStrayRequests();

        try {
            $connector->send(new UserRequest);

            $this->fail('A stray request was sent.');
        } catch (StrayRequestException) {
        }

        Http::allowStrayRequests();

        $this->assertSame(200, $connector->send(new UserRequest)->status());
    }

    /**
     * Get the test server URL.
     */
    protected function serverUrl(): string
    {
        return sprintf('http://%s:%d', $this->getServerHost(), $this->getServerPort());
    }
}
