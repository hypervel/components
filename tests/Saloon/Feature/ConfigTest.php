<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Feature;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Saloon\SaloonServiceProvider;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Saloon\Fixtures\Connectors\TestConnector;
use Hypervel\Tests\Saloon\Fixtures\Requests\UserRequest;

class ConfigTest extends TestCase
{
    /**
     * Get the package providers.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [SaloonServiceProvider::class];
    }

    public function testDefaultGuzzleConfigOptionsAreSent(): void
    {
        $options = $this->captureOptions();

        (new TestConnector)->send(new UserRequest);

        // The timeouts come from the Saloon HTTP connection. Saloon builds its own request exceptions, so it always
        // sends `http_errors` as false where upstream's Guzzle sender defaults it to true.
        $this->assertFalse($options->value['http_errors']);
        $this->assertSame(10, $options->value['connect_timeout']);
        $this->assertSame(30, $options->value['timeout']);
    }

    public function testYouCanPassAdditionalGuzzleConfigOptionsAndTheyAreMergedFromTheConnectorAndRequest(): void
    {
        $options = $this->captureOptions();

        // Connectors are read-only, so connector options come from defaultOptions() instead of runtime changes.
        $connector = new DebugOptionsConnectorStub;

        $request = new UserRequest;

        $request->withOptions(['verify' => false]);

        $connector->send($request);

        $this->assertFalse($options->value['http_errors']);
        $this->assertSame(10, $options->value['connect_timeout']);
        $this->assertSame(30, $options->value['timeout']);
        $this->assertTrue($options->value['debug']);
        $this->assertFalse($options->value['verify']);
    }

    public function testRemovingARequestOptionRestoresTheHttpConnectionValue(): void
    {
        $options = $this->captureOptions();

        (new TestConnector)->send((new UserRequest)->timeout(5)->withoutOptions('timeout'));

        $this->assertSame(30, $options->value['timeout']);
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

class DebugOptionsConnectorStub extends TestConnector
{
    /**
     * Resolve the default request options.
     *
     * @return array<string, mixed>
     */
    protected function defaultOptions(): array
    {
        return ['debug' => true];
    }
}
