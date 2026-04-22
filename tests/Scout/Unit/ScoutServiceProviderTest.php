<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit;

use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Algolia\AlgoliaSearch\Http\GuzzleHttpClient;
use GuzzleHttp\Client as GuzzleClient;
use Http\Client\Common\HttpMethodsClient;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testbench\TestCase;
use Meilisearch\Client as MeilisearchClient;
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

    public function testMeilisearchClientUsesExplicitGuzzle()
    {
        // Closes the pre-existing gap where the binding fell through to
        // Psr18ClientDiscovery::find(). Verifies our explicit Guzzle injection
        // reaches the adapter's inner PSR-18 client.
        $client = $this->app->make(MeilisearchClient::class);

        // Meilisearch\Client::$http is the MeilisearchClientAdapter
        // (Meilisearch\Http\Client); that adapter's private $http is the
        // PSR-18 implementation we injected.
        $adapter = (new ClassInvoker($client))->http;
        $psr18 = (new ClassInvoker($adapter))->http;

        $this->assertInstanceOf(GuzzleClient::class, $psr18);
    }

    public function testAlgoliaClientIsRegistered()
    {
        // Algolia4SearchConfig throws if appId or apiKey is empty, so seed
        // non-empty credentials before resolving.
        $this->app->make('config')->set('scout.algolia.id', 'test-app-id');
        $this->app->make('config')->set('scout.algolia.secret', 'test-secret');
        $this->app->forgetInstance(AlgoliaSearchClient::class);

        $client = $this->app->make(AlgoliaSearchClient::class);

        $this->assertInstanceOf(AlgoliaSearchClient::class, $client);
    }

    public function testAlgoliaClientUsesExplicitGuzzle()
    {
        // Pins the behaviour: the Algolia binding calls
        // Algolia::setHttpClient(new GuzzleHttpClient(new GuzzleClient))
        // and that client is what the Algolia SDK uses afterwards.
        $this->app->make('config')->set('scout.algolia.id', 'test-app-id');
        $this->app->make('config')->set('scout.algolia.secret', 'test-secret');
        $this->app->forgetInstance(AlgoliaSearchClient::class);

        // Resolving triggers the binding closure which calls setHttpClient.
        $this->app->make(AlgoliaSearchClient::class);

        $wrapper = Algolia::getHttpClient();
        $this->assertInstanceOf(GuzzleHttpClient::class, $wrapper);

        // GuzzleHttpClient stores the injected Guzzle in a private $client
        // property (see vendor/algolia/algoliasearch-client-php/lib/Http/
        // GuzzleHttpClient.php). Verify it's a real GuzzleHttp\Client.
        $inner = (new ClassInvoker($wrapper))->client;
        $this->assertInstanceOf(GuzzleClient::class, $inner);
    }

    public function testAlgoliaClientHasScoutTelescopeTags()
    {
        $this->app->make('config')->set('scout.algolia.id', 'test-app-id');
        $this->app->make('config')->set('scout.algolia.secret', 'test-secret');
        $this->app->forgetInstance(AlgoliaSearchClient::class);

        $this->app->make(AlgoliaSearchClient::class);

        /** @var GuzzleClient $inner */
        $inner = (new ClassInvoker(Algolia::getHttpClient()))->client;

        $this->assertSame(
            [TelescopeTag::Scout, TelescopeTag::Algolia],
            $inner->getConfig('telescope_tags'),
        );
    }

    public function testMeilisearchClientHasScoutTelescopeTags()
    {
        /** @var MeilisearchClient $client */
        $client = $this->app->make(MeilisearchClient::class);

        $adapter = (new ClassInvoker($client))->http;
        /** @var GuzzleClient $psr18 */
        $psr18 = (new ClassInvoker($adapter))->http;

        $this->assertSame(
            [TelescopeTag::Scout, TelescopeTag::Meilisearch],
            $psr18->getConfig('telescope_tags'),
        );
    }

    public function testTypesenseClientHasScoutTelescopeTags()
    {
        $this->app->make('config')->set('scout.typesense.client-settings', [
            'api_key' => 'test-key',
            'nodes' => [
                ['host' => 'localhost', 'port' => '8108', 'protocol' => 'http'],
            ],
        ]);
        $this->app->forgetInstance(TypesenseClient::class);

        $typesense = $this->app->make(TypesenseClient::class);

        $config = (new ReflectionProperty($typesense, 'config'))->getValue($typesense);
        $client = $config->getClient();

        // Typesense wraps injected PSR-18 clients in HttpMethodsClient; the
        // real Guzzle sits inside its $httpClient property.
        if ($client instanceof HttpMethodsClient) {
            /** @var GuzzleClient $client */
            $client = (new ReflectionProperty(HttpMethodsClient::class, 'httpClient'))->getValue($client);
        }

        $this->assertSame(
            [TelescopeTag::Scout, TelescopeTag::Typesense],
            $client->getConfig('telescope_tags'),
        );
    }
}
