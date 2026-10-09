<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Gateway\Concerns;

use Hypervel\Ai\AiManager;
use Hypervel\Ai\AiServiceProvider;
use Hypervel\Ai\Contracts\Providers\Provider;
use Hypervel\Ai\Gateway\Concerns\CreatesClient;
use Hypervel\Ai\Gateway\OpenAi\Concerns\CreatesOpenAiClient;
use Hypervel\Ai\Providers\Provider as BaseProvider;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Http\Client\PendingRequest;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use Mockery as m;

class CreatesClientTest extends TestCase
{
    /**
     * Register the package's services.
     */
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [AiServiceProvider::class];
    }

    /**
     * Configure an application-owned provider before package boot.
     */
    protected function defineEnvironment(ApplicationContract $app): void
    {
        $app->make('config')->set('ai.providers.configured', ['driver' => 'openai', 'key' => 'configured-key']);
    }

    public function testConfiguredHeadersOverrideDefaultsCaseInsensitively(): void
    {
        $headers = $this->httpClient(
            ['Authorization' => 'Bearer provider-key', 'Content-Type' => 'application/json'],
            [
                'authorization' => 'Bearer proxy-token',
                'X-Session-Affinity' => 'discarded',
                'x-session-affinity' => 'abc-123',
            ],
        )->getOptions()['headers'];

        $this->assertSame([
            'Authorization' => 'Bearer proxy-token',
            'Content-Type' => 'application/json',
            'X-Session-Affinity' => 'abc-123',
        ], $headers);
    }

    public function testSelectsRegisteredConnectionsWithoutRetainingDynamicProviderNames(): void
    {
        $connections = Http::getConnectionConfigs();
        $this->assertSame([], $connections[AiManager::HTTP_CONNECTION]);
        $this->assertSame([], $connections[AiManager::HTTP_CONNECTION . '.configured']);
        $this->assertSame([], $connections[AiManager::HTTP_CONNECTION . '.openai']);
        config(['ai.providers.added-later' => ['driver' => 'openai', 'key' => 'test-key']]);

        foreach (['configured', 'on-demand', 'added-later'] as $name) {
            $provider = m::mock(Provider::class);
            $provider->shouldReceive('name')->andReturn($name);
            $client = $this->httpClient(provider: $provider);

            $this->assertSame(
                $name === 'configured' ? AiManager::HTTP_CONNECTION . '.configured' : AiManager::HTTP_CONNECTION,
                $client->getConnection(),
            );
        }

        $this->assertSame($connections, Http::getConnectionConfigs());
    }

    public function testProviderCredentialsAndOptionsRemainOnEachRequest(): void
    {
        Http::registerConnection(AiManager::HTTP_CONNECTION . '.configured', ['connect_timeout' => 7]);
        $gateway = new class {
            use CreatesOpenAiClient {
                client as public;
            }
        };

        $requests = [];

        foreach (['first', 'second'] as $index => $key) {
            $provider = m::mock(BaseProvider::class);
            $provider->shouldReceive('name')->andReturn('configured');
            $provider->shouldReceive('providerCredentials')->andReturn(['key' => $key]);
            $provider->shouldReceive('additionalConfiguration')->andReturn(['headers' => ['X-Request' => $key]]);
            $requests[] = $gateway->client($provider, $index + 1);
        }

        foreach ($requests as $index => $request) {
            $key = $index === 0 ? 'first' : 'second';
            $options = $request->getOptions();

            $this->assertSame(AiManager::HTTP_CONNECTION . '.configured', $request->getConnection());
            $this->assertSame('Bearer ' . $key, $options['headers']['Authorization']);
            $this->assertSame($key, $options['headers']['X-Request']);
            $this->assertSame($index + 1, $options['timeout']);
            $this->assertSame(7, $options['connect_timeout']);
        }

        $this->assertSame(['connect_timeout' => 7], Http::getConnectionConfigs()[AiManager::HTTP_CONNECTION . '.configured']);
    }

    /**
     * Build a request through the shared gateway client methods.
     */
    protected function httpClient(array $headers = [], array $configuredHeaders = [], ?Provider $provider = null): PendingRequest
    {
        return (new class {
            use CreatesClient;

            /**
             * Create a client for the given headers and provider.
             */
            public function client(array $headers, array $configuredHeaders, ?Provider $provider): PendingRequest
            {
                $client = $this->createClient('https://example.com', $headers, $configuredHeaders);

                return $provider === null ? $client : $client->connection($this->httpConnection($provider));
            }
        })->client($headers, $configuredHeaders, $provider);
    }
}
