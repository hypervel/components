<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use GuzzleHttp\Client as GuzzleClient;
use Http\Client\Common\HttpMethodsClient;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Testbench\TestCase;
use Psr\Http\Client\ClientInterface;
use ReflectionProperty;
use Typesense\Client as TypesenseClient;

class ScoutServiceProviderTest extends TestCase
{
    protected function getPackageProviders(Application $app): array
    {
        return [ScoutServiceProvider::class];
    }

    public function testTypesenseClientUsesGuzzle()
    {
        $this->app->make('config')->set('scout.typesense.client-settings', [
            'api_key' => 'test-key',
            'nodes' => [
                ['host' => 'localhost', 'port' => '8108', 'protocol' => 'http'],
            ],
        ]);

        // Flush so it resolves with the config we just set.
        $this->app->forgetInstance(TypesenseClient::class);

        $typesense = $this->app->make(TypesenseClient::class);

        // Access the internal client via reflection to verify Guzzle was injected.
        $config = (new ReflectionProperty($typesense, 'config'))->getValue($typesense);
        $client = $config->getClient();

        // When a PSR-18 ClientInterface is passed directly (not HttpMethodsClient),
        // Configuration::getClient() wraps it in HttpMethodsClient. Verify the
        // underlying client is Guzzle.
        if ($client instanceof HttpMethodsClient) {
            $inner = (new ReflectionProperty(HttpMethodsClient::class, 'httpClient'))->getValue($client);
            $this->assertInstanceOf(GuzzleClient::class, $inner);
        } else {
            $this->assertInstanceOf(GuzzleClient::class, $client);
        }
    }

    public function testTypesenseClientRespectsExplicitClientConfig()
    {
        $customClient = new GuzzleClient(['timeout' => 99]);

        $this->app->make('config')->set('scout.typesense.client-settings', [
            'api_key' => 'test-key',
            'nodes' => [
                ['host' => 'localhost', 'port' => '8108', 'protocol' => 'http'],
            ],
            'client' => $customClient,
        ]);

        // Flush the singleton so it re-resolves with the new config.
        $this->app->forgetInstance(TypesenseClient::class);

        $typesense = $this->app->make(TypesenseClient::class);

        $config = (new ReflectionProperty($typesense, 'config'))->getValue($typesense);
        $client = $config->getClient();

        // When a user provides their own client, it should be used as-is
        // (not overwritten by our default GuzzleClient).
        $this->assertSame($customClient, $client);
    }
}
