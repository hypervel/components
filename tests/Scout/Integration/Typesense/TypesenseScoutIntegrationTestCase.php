<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Integration\Typesense;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Scout\Console\DeleteIndexCommand;
use Hypervel\Scout\Console\FlushCommand;
use Hypervel\Scout\Console\ImportCommand;
use Hypervel\Scout\Console\IndexCommand;
use Hypervel\Scout\Console\SyncIndexSettingsCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\TypesenseEngine;
use Hypervel\Scout\ScoutServiceProvider;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Base test case for Typesense Scout integration tests.
 *
 * Combines database support with Typesense connectivity for testing
 * the full Scout workflow with real Typesense instance.
 *
 * @group integration
 * @group typesense-integration
 *
 * @internal
 * @coversNothing
 */
abstract class TypesenseScoutIntegrationTestCase extends TestCase
{
    use RefreshDatabase;
    use RunTestsInCoroutine;

    protected bool $migrateRefresh = true;

    protected string $basePrefix = 'scout_int_';

    protected string $testPrefix;

    protected TypesenseClient $typesense;

    protected TypesenseEngine $engine;

    protected function setUp(): void
    {
        if (! env('RUN_TYPESENSE_INTEGRATION_TESTS', false)) {
            $this->markTestSkipped(
                'Typesense integration tests are disabled. Set RUN_TYPESENSE_INTEGRATION_TESTS=true to enable.'
            );
        }

        $this->computeTestPrefix();

        parent::setUp();

        $this->app->register(ScoutServiceProvider::class);
        $this->configureTypesense();
        $this->registerScoutCommands();

        // Clear cached engines so they're recreated with our test config
        $this->app->get(EngineManager::class)->forgetEngines();
    }

    /**
     * Register Scout commands with the Artisan application.
     *
     * Commands registered via ServiceProvider::commands() after the app is
     * bootstrapped won't be available unless we manually resolve them.
     */
    protected function registerScoutCommands(): void
    {
        Artisan::getArtisan()->resolveCommands([
            DeleteIndexCommand::class,
            FlushCommand::class,
            ImportCommand::class,
            IndexCommand::class,
            SyncIndexSettingsCommand::class,
        ]);
    }

    protected function setUpInCoroutine(): void
    {
        $this->typesense = $this->app->get(TypesenseClient::class);
        $this->engine = $this->app->get(EngineManager::class)->engine('typesense');
        $this->cleanupTestCollections();
    }

    protected function tearDownInCoroutine(): void
    {
        $this->cleanupTestCollections();
    }

    protected function computeTestPrefix(): void
    {
        $testToken = env('TEST_TOKEN', '');
        $this->testPrefix = $testToken !== ''
            ? "{$this->basePrefix}{$testToken}_"
            : "{$this->basePrefix}";
    }

    protected function configureTypesense(): void
    {
        $config = $this->app->get(ConfigInterface::class);

        $host = env('TYPESENSE_HOST', '127.0.0.1');
        $port = env('TYPESENSE_PORT', '8108');
        $protocol = env('TYPESENSE_PROTOCOL', 'http');
        $apiKey = env('TYPESENSE_API_KEY', '');

        $config->set('scout.driver', 'typesense');
        $config->set('scout.prefix', $this->testPrefix);
        $config->set('scout.soft_delete', false);
        $config->set('scout.queue.enabled', false);
        $config->set('scout.typesense.client-settings', [
            'api_key' => $apiKey,
            'nodes' => [
                [
                    'host' => $host,
                    'port' => $port,
                    'protocol' => $protocol,
                ],
            ],
            'connection_timeout_seconds' => 2,
        ]);
        $config->set('scout.typesense.max_total_results', 1000);
    }

    protected function migrateFreshUsing(): array
    {
        return [
            '--seed' => $this->shouldSeed(),
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => [
                dirname(__DIR__, 2) . '/migrations',
            ],
        ];
    }

    protected function prefixedCollectionName(string $name): string
    {
        return $this->testPrefix . $name;
    }

    protected function cleanupTestCollections(): void
    {
        try {
            $collections = $this->typesense->collections->retrieve();

            foreach ($collections as $collection) {
                if (str_starts_with($collection['name'], $this->testPrefix)) {
                    $this->typesense->collections[$collection['name']]->delete();
                }
            }
        } catch (Throwable) {
            // Ignore errors during cleanup
        }
    }
}
