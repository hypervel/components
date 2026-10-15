<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Algolia\AlgoliaSearch\Algolia;
use Algolia\AlgoliaSearch\Api\SearchClient as AlgoliaSearchClient;
use Algolia\AlgoliaSearch\Configuration\SearchConfig as AlgoliaSearchConfig;
use Algolia\AlgoliaSearch\Http\GuzzleHttpClient;
use Algolia\AlgoliaSearch\Support\AlgoliaAgent;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\HandlerStack;
use Hypervel\Contracts\Telescope\TelescopeTag;
use Hypervel\Foundation\Application as HypervelApplication;
use Hypervel\Http\Client\Factory as HttpFactory;
use Hypervel\Http\Client\Guzzle\HandlerFactory;
use Hypervel\Http\Client\Guzzle\ReleaseIdleDatabaseConnections;
use Hypervel\Scout\Console\DeleteAllIndexesCommand;
use Hypervel\Scout\Console\DeleteIndexCommand;
use Hypervel\Scout\Console\FlushCommand;
use Hypervel\Scout\Console\ImportCommand;
use Hypervel\Scout\Console\IndexCommand;
use Hypervel\Scout\Console\QueueImportCommand;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Scout\Engines\MeilisearchRetryPolicy;
use Hypervel\Scout\Services\Turbopuffer\TurbopufferClient;
use Hypervel\Support\ServiceProvider;
use Meilisearch\Client as MeilisearchClient;
use Typesense\Client as TypesenseClient;

class ScoutServiceProvider extends ServiceProvider
{
    /**
     * Register Scout services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            dirname(__DIR__) . '/config/scout.php',
            'scout'
        );

        $this->registerAlgoliaClient();
        $this->registerMeilisearchClient();
        $this->registerTypesenseClient();
        $this->registerTurbopufferClient();
    }

    /**
     * Bootstrap Scout services.
     */
    public function boot(): void
    {
        $this->configureAlgoliaSdk();

        if ($this->app->runningInConsole()) {
            $this->registerPublishing();
            $this->registerCommands();
        }
    }

    /**
     * Configure Algolia's SDK-wide settings.
     */
    protected function configureAlgoliaSdk(): void
    {
        if (! class_exists(Algolia::class)) {
            return;
        }

        // Pin the HTTP client to Guzzle explicitly rather than relying on
        // Algolia::getHttpClient()'s internal auto-decide heuristic. The
        // heuristic can change under ^4.0 minor releases (swap to PSR-18
        // discovery, reorder Guzzle detection, etc.) with no semver signal.
        // Explicit injection pins the HTTP client choice at our boundary.
        Algolia::setHttpClient(new GuzzleHttpClient(new GuzzleClient([
            'telescope_tags' => [TelescopeTag::Scout, TelescopeTag::Algolia],
            'handler' => $this->createHttpHandler(),
        ])));

        AlgoliaAgent::addAlgoliaAgent('Hypervel Scout', 'Hypervel Scout', HypervelApplication::VERSION);
    }

    /**
     * Register the Algolia search client.
     */
    protected function registerAlgoliaClient(): void
    {
        $this->app->singleton(AlgoliaSearchClient::class, function () {
            $config = $this->app->make('config');

            $algoliaConfig = new AlgoliaSearchConfig([
                'appId' => $config->string('scout.algolia.id'),
                'apiKey' => $config->string('scout.algolia.secret'),
            ]);

            if (is_int($connectTimeout = $config->get('scout.algolia.connect_timeout'))) {
                $algoliaConfig->setConnectTimeout($connectTimeout);
            }
            if (is_int($readTimeout = $config->get('scout.algolia.read_timeout'))) {
                $algoliaConfig->setReadTimeout($readTimeout);
            }
            if (is_int($writeTimeout = $config->get('scout.algolia.write_timeout'))) {
                $algoliaConfig->setWriteTimeout($writeTimeout);
            }

            return AlgoliaSearchClient::createWithConfig($algoliaConfig);
        });
    }

    /**
     * Register the Meilisearch client.
     */
    protected function registerMeilisearchClient(): void
    {
        $this->app->singleton(MeilisearchClient::class, function () {
            $config = $this->app->make('config');

            $stack = $this->createHttpHandler();
            $guzzleOptions = [
                'handler' => $stack,
                'telescope_tags' => [TelescopeTag::Scout, TelescopeTag::Meilisearch],
            ];

            // The meilisearch/meilisearch-php client has no built-in retry
            // mechanism (unlike Algolia's PHP client which has host failover,
            // and Typesense's which has num_retries). Add HTTP-level retry at
            // the Guzzle layer for parity, using MeilisearchRetryPolicy to
            // decide what to retry and how long to wait between attempts.
            $maxRetries = $config->integer('scout.meilisearch.retries', 3);
            $baseDelayMs = $config->integer('scout.meilisearch.initial_retry_delay_ms', 100);

            if ($maxRetries > 0) {
                $stack->before('release_idle_database_connections', MeilisearchRetryPolicy::middleware($maxRetries, $baseDelayMs));
            }

            // Inject Guzzle explicitly so the Meilisearch client never falls
            // back to Psr18ClientDiscovery::find(), which may resolve to a
            // Swoole-unsafe PSR-18 implementation (e.g. Symfony's
            // CurlHttpClient). Mirrors the Typesense binding's defensive pattern.
            return new MeilisearchClient(
                $config->string('scout.meilisearch.host', 'http://localhost:7700'),
                $config->get('scout.meilisearch.key'),
                new GuzzleClient($guzzleOptions),
            );
        });
    }

    /**
     * Register the Typesense client.
     */
    protected function registerTypesenseClient(): void
    {
        $this->app->singleton(TypesenseClient::class, function () {
            $config = $this->app->make('config');
            $settings = $config->array('scout.typesense.client-settings');

            // Explicitly inject Guzzle as the HTTP client so Typesense never
            // falls back to PSR-18 auto-discovery, which may resolve to
            // Symfony's CurlHttpClient (unsafe with Swoole coroutines).
            $settings['client'] ??= new GuzzleClient([
                'telescope_tags' => [TelescopeTag::Scout, TelescopeTag::Typesense],
                'handler' => $this->createHttpHandler(),
            ]);

            return new TypesenseClient($settings);
        });
    }

    /**
     * Create the reusable SDK transport and return idle database sessions before requests.
     */
    protected function createHttpHandler(): HandlerStack
    {
        $stack = HandlerStack::create(HandlerFactory::create());
        $stack->push(new ReleaseIdleDatabaseConnections, 'release_idle_database_connections');

        return $stack;
    }

    /**
     * Register the Turbopuffer client.
     */
    protected function registerTurbopufferClient(): void
    {
        $this->app->singleton(TurbopufferClient::class, function (): TurbopufferClient {
            return new TurbopufferClient(
                $this->app->make(HttpFactory::class),
                $this->app->make('config')->array('scout.turbopuffer', []),
            );
        });
    }

    /**
     * Register the package's publishable resources.
     */
    protected function registerPublishing(): void
    {
        $this->publishes([
            dirname(__DIR__) . '/config/scout.php' => config_path('scout.php'),
        ], 'scout-config');
    }

    /**
     * Register the package's Artisan commands.
     */
    protected function registerCommands(): void
    {
        $this->commands([
            DeleteAllIndexesCommand::class,
            DeleteIndexCommand::class,
            FlushCommand::class,
            ImportCommand::class,
            IndexCommand::class,
            QueueImportCommand::class,
            SyncIndexSettingsCommand::class,
        ]);
    }
}
