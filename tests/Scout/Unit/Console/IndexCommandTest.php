<?php

declare(strict_types=1);

namespace Hypervel\Tests\Scout\Unit\Console;

use Hypervel\Config\Repository;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Scout\Console\IndexCommand;
use Hypervel\Scout\EngineManager;
use Hypervel\Scout\Engines\Engine;
use Hypervel\Scout\Exceptions\NotSupportedException;
use Hypervel\Scout\Scout;
use Hypervel\Tests\Scout\Fixtures\SettingsEngine;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\DoubleInterface;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use RuntimeException;

class IndexCommandTest extends TestCase
{
    use VerifiesDoubles;

    public function testZeroPrimaryKeyReachesTheEngine(): void
    {
        $engine = Double::for(Engine::class)->strict();
        $engine->expects('createIndex')
            ->with('prod_posts', ['primaryKey' => '0']);

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')->returns($engine);

        $config = new Repository(['scout' => ['prefix' => 'prod_']]);

        $command = $this->command('posts', '0');
        $command->expects('info')->with('Synchronized index ["prod_posts"] successfully.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testUnsupportedCreationStillAppliesLogicalIndexSettings(): void
    {
        $engine = Double::for(SettingsEngine::class)->strict();
        $engine->expects('createIndex')
            ->with('prod_posts', [])
            ->throws(new NotSupportedException);
        $engine->expects('updateIndexSettings')
            ->with('prod_posts', ['filterableAttributes' => ['status']]);

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')->returns($engine);

        $config = new Repository(['scout' => [
            'prefix' => 'prod_',
            'driver' => 'meilisearch',
            'meilisearch' => ['index-settings' => [
                'posts' => ['filterableAttributes' => ['status']],
            ]],
        ]]);

        $command = $this->command('posts');
        $command->expects('info')->with('Synchronized index ["prod_posts"] successfully.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testPhysicalIndexSettingsAreUsedAsFallback(): void
    {
        $engine = Double::for(SettingsEngine::class)->strict();
        $engine->expects('createIndex')->with('prod_posts', []);
        $engine->expects('updateIndexSettings')
            ->with('prod_posts', ['sortableAttributes' => ['created_at']]);

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')->returns($engine);

        $config = new Repository(['scout' => [
            'prefix' => 'prod_',
            'driver' => 'meilisearch',
            'meilisearch' => ['index-settings' => [
                'prod_posts' => ['sortableAttributes' => ['created_at']],
            ]],
        ]]);

        $command = $this->command('posts');
        $command->expects('info')->with('Synchronized index ["prod_posts"] successfully.');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testLifecycleCallbackCanPrepareAnEmptyNamedIndexEntry(): void
    {
        $engine = Double::for(SettingsEngine::class)->strict();
        $engine->expects('createIndex')->with('prod_posts', []);
        $engine->expects('updateIndexSettings')
            ->with('prod_posts', ['filterableAttributes' => ['tenant_id']]);
        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')->returns($engine);
        $config = new Repository(['scout' => [
            'prefix' => 'prod_',
            'driver' => 'meilisearch',
        ]]);

        Scout::prepareIndexSettingsUsing(function (
            array $settings,
            ?Model $model,
            Engine $givenEngine,
            string $index
        ) use ($engine): array {
            $this->assertSame([], $settings);
            $this->assertNull($model);
            $this->assertSame($engine, $givenEngine);
            $this->assertSame('prod_posts', $index);

            return ['filterableAttributes' => ['tenant_id']];
        });

        $command = $this->command('posts');
        $command->expects('info');

        $this->assertSame(0, $command->handle($manager, $config));
    }

    public function testOperationalCreationFailuresPropagate(): void
    {
        $failure = new RuntimeException('Search service unavailable.');
        $engine = Double::for(Engine::class)->strict();
        $engine->expects('createIndex')->throws($failure);

        $manager = Double::for(EngineManager::class)->strict();
        $manager->expects('engine')->returns($engine);

        $config = new Repository(['scout' => ['prefix' => '']]);

        $command = $this->command('posts');
        $command->expects('info')->never();

        try {
            $command->handle($manager, $config);
            $this->fail('The operational failure was swallowed.');
        } catch (RuntimeException $exception) {
            $this->assertSame($failure, $exception);
        }
    }

    /**
     * Create a command double with the given index arguments.
     */
    protected function command(string $name, ?string $key = null): IndexCommand&DoubleInterface
    {
        $command = Double::for(new IndexCommand)->passthru();
        $command->allows('argument')->with('name')->returns($name);
        $command->allows('option')->with('key')->returns($key);

        return $command;
    }
}
