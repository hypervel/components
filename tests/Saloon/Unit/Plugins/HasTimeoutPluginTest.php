<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Unit\Plugins;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\Http\Faking\MockClient;
use Hypervel\Saloon\Http\Faking\MockResponse;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Saloon\Traits\Plugins\HasTimeout;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TimeoutConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\TimeoutRequest;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class HasTimeoutPluginTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testARequestIsGivenADefaultTimeoutAndConnectTimeout(): void
    {
        $options = $this->captureOptions();

        // Hypervel's defaults come from the Saloon HTTP connection rather than the plugin.
        (new TestConnector)->send(UserRequest::make());

        $this->assertSame(10, $options->value['connect_timeout']);
        $this->assertSame(30, $options->value['timeout']);
    }

    public function testARequestCanSetATimeoutAndConnectTimeout(): void
    {
        $request = new TimeoutRequest;
        $pendingRequest = (new TestConnector)
            ->send($request, new MockClient([MockResponse::make()]))
            ->pendingRequest();

        $options = $pendingRequest->options();

        $this->assertSame(1.0, $options['connect_timeout']);
        $this->assertSame(2.0, $options['timeout']);
    }

    public function testAConnectorIsGivenADefaultTimeoutAndConnectTimeout(): void
    {
        $connector = new TimeoutConnector;

        $pendingRequest = $connector
            ->send(new UserRequest, new MockClient([MockResponse::make()]))
            ->pendingRequest();

        $options = $pendingRequest->options();

        $this->assertSame(10.0, $options['connect_timeout']);
        $this->assertSame(5.0, $options['timeout']);

        $transportOptions = $this->captureOptions();

        $connector->send(new UserRequest);

        $this->assertSame(10.0, $transportOptions->value['connect_timeout']);
        $this->assertSame(5.0, $transportOptions->value['timeout']);
    }

    public function testRequestOptionsOverrideTheConnectorTimeouts(): void
    {
        $request = (new UserRequest)->timeout(1)->connectTimeout(0);

        $options = (new TimeoutConnector)
            ->send($request, new MockClient([MockResponse::make()]))
            ->pendingRequest()
            ->options();

        $this->assertSame(0, $options['connect_timeout']);
        $this->assertSame(1, $options['timeout']);
    }

    public function testRequestOptionsOverrideTheRequestTimeouts(): void
    {
        $options = (new TestConnector)
            ->send((new TimeoutRequest)->timeout(0), new MockClient([MockResponse::make()]))
            ->pendingRequest()
            ->options();

        $this->assertSame(1.0, $options['connect_timeout']);
        $this->assertSame(0, $options['timeout']);
    }

    public function testRequestTimeoutsOverrideTheConnectorTimeouts(): void
    {
        $options = (new TimeoutConnector)
            ->send(new TimeoutRequest, new MockClient([MockResponse::make()]))
            ->pendingRequest()
            ->options();

        $this->assertSame(1.0, $options['connect_timeout']);
        $this->assertSame(2.0, $options['timeout']);
    }

    public function testConnectorOptionsOverrideOnlyTheConnectorTimeouts(): void
    {
        $connector = new ConfiguredTimeoutConnectorStub;

        $connectorOptions = $connector
            ->send(new UserRequest, new MockClient([MockResponse::make()]))
            ->pendingRequest()
            ->options();
        $requestOptions = $connector
            ->send(new TimeoutRequest, new MockClient([MockResponse::make()]))
            ->pendingRequest()
            ->options();

        $this->assertSame(20, $connectorOptions['timeout']);
        $this->assertSame(2.0, $requestOptions['timeout']);
    }

    public function testUndeclaredTimeoutsKeepTheHttpConnectionDefaults(): void
    {
        $this->app->make(Factory::class)->registerConnection('slow', ['connect_timeout' => 15, 'timeout' => 90]);
        $options = $this->captureOptions();

        $pendingRequest = (new ConnectionTimeoutConnectorStub)->send(new UserRequest)->pendingRequest();

        $this->assertSame([], $pendingRequest->options());
        $this->assertSame(15, $options->value['connect_timeout']);
        $this->assertSame(90, $options->value['timeout']);
    }

    public function testTheTimeoutGettersMayBeOverridden(): void
    {
        $this->app->make(Factory::class)->registerConnection('slow', ['connect_timeout' => 15, 'timeout' => 90]);
        $options = $this->captureOptions();

        (new OverriddenTimeoutConnectorStub)->send(new UserRequest);

        $this->assertSame(15, $options->value['connect_timeout']);
        $this->assertSame(12.5, $options->value['timeout']);
    }

    /**
     * Capture the transport options of the next faked request.
     *
     * @return object{value: array<string, mixed>}
     */
    protected function captureOptions(): object
    {
        $captured = new class {
            public array $value = [];
        };

        Http::fake(function (HttpRequest $request, array $options) use ($captured): PromiseInterface {
            $captured->value = $options;

            return Http::response();
        });

        return $captured;
    }
}

class ConnectionTimeoutConnectorStub extends TestConnector
{
    use HasTimeout;

    /**
     * Resolve the HTTP connection used by this connector.
     */
    public function resolveHttpConnection(): ?string
    {
        return 'slow';
    }
}

class OverriddenTimeoutConnectorStub extends ConnectionTimeoutConnectorStub
{
    /**
     * Get the request timeout.
     */
    public function getRequestTimeout(): ?float
    {
        return 12.5;
    }
}

class ConfiguredTimeoutConnectorStub extends TimeoutConnector
{
    /**
     * Resolve the default request options.
     *
     * @return array<string, mixed>
     */
    protected function defaultOptions(): array
    {
        return ['timeout' => 20];
    }
}
