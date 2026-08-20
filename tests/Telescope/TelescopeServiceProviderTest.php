<?php

declare(strict_types=1);

namespace Hypervel\Tests\Telescope;

use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Config\Repository as ConfigRepository;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Telescope\Contracts\ClearableRepository;
use Hypervel\Telescope\Contracts\EntriesRepository;
use Hypervel\Telescope\Contracts\PrunableRepository;
use Hypervel\Telescope\Storage\DatabaseEntriesRepository;
use Hypervel\Telescope\Telescope;
use Hypervel\Telescope\TelescopeServiceProvider;
use InvalidArgumentException;
use Mockery as m;
use ReflectionProperty;

class TelescopeServiceProviderTest extends FeatureTestCase
{
    protected bool $yieldBeforeTelescopeContextPropagation = false;

    protected function defineEnvironment(ApplicationContract $app): void
    {
        // This runs before package providers boot, so the callback precedes Telescope's.
        Coroutine::afterCreated(function (): void {
            if ($this->yieldBeforeTelescopeContextPropagation) {
                Coroutine::sleep(0.01);
            }
        });

        parent::defineEnvironment($app);
    }

    public function testForkPreservesCapturedTelescopeContext(): void
    {
        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, false);
        $this->yieldBeforeTelescopeContextPropagation = true;
        $recording = null;
        $parentRecording = null;

        $coroutineId = Coroutine::fork(function () use (&$recording, &$parentRecording): void {
            $recording = Telescope::isRecording();
            $parentRecording = CoroutineContext::get(
                Telescope::SHOULD_RECORD_CONTEXT_KEY,
                null,
                Coroutine::parentId(),
            );
        });

        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, true);
        Coroutine::join([$coroutineId]);

        $this->assertFalse($recording);
        $this->assertTrue($parentRecording);
    }

    public function testCreateInheritsTelescopeContextFromParent(): void
    {
        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, false);
        $this->yieldBeforeTelescopeContextPropagation = true;
        $recording = null;

        $coroutineId = Coroutine::create(function () use (&$recording): void {
            $recording = Telescope::isRecording();
        });

        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, true);
        Coroutine::join([$coroutineId]);

        $this->assertTrue($recording);
    }

    public function testForkInheritsOmittedTelescopeContextFromParent(): void
    {
        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, false);
        CoroutineContext::set('telescope-test.selected', 'selected');
        $this->yieldBeforeTelescopeContextPropagation = true;
        $observed = null;

        $coroutineId = Coroutine::fork(function () use (&$observed): void {
            $observed = [
                Telescope::isRecording(),
                CoroutineContext::get('telescope-test.selected'),
            ];
        }, ['telescope-test.selected']);

        CoroutineContext::set(Telescope::SHOULD_RECORD_CONTEXT_KEY, true);
        Coroutine::join([$coroutineId]);

        $this->assertSame([true, 'selected'], $observed);
    }

    public function testRouteRegistrationRequiresStringPath(): void
    {
        config()->set('telescope.path', null);

        $provider = new class($this->app) extends TelescopeServiceProvider {
            public function registerRoutesForTest(): void
            {
                $this->registerRoutes();
            }
        };

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Configuration value for key [telescope.path] must be a string');

        $provider->registerRoutesForTest();
    }

    public function testReloadConfigurationUsesDefaultChunkSizeWhenSettingIsOmitted(): void
    {
        $repository = $this->app->make(EntriesRepository::class);
        $telescope = config()->array('telescope');

        $this->assertSame(
            DatabaseEntriesRepository::DEFAULT_CHUNK_SIZE,
            $telescope['storage']['database']['chunk'],
        );

        unset($telescope['storage']['database']['chunk']);
        config()->set('telescope', $telescope);

        (new TelescopeServiceProvider($this->app))->reloadConfiguration();

        $chunkSize = new ReflectionProperty(DatabaseEntriesRepository::class, 'chunkSize');

        $this->assertSame(DatabaseEntriesRepository::DEFAULT_CHUNK_SIZE, $chunkSize->getValue($repository));
    }

    public function testReloadConfigurationUpdatesEveryResolvedDatabaseRepositoryInPlace(): void
    {
        $entries = $this->app->make(EntriesRepository::class);
        $clearable = $this->app->make(ClearableRepository::class);
        $prunable = $this->app->make(PrunableRepository::class);
        $store = new ReflectionProperty(Telescope::class, 'store');
        $connection = new ReflectionProperty(DatabaseEntriesRepository::class, 'connection');
        $chunkSize = new ReflectionProperty(DatabaseEntriesRepository::class, 'chunkSize');
        $instances = new ReflectionProperty(Container::class, 'instances');
        $autoSingletons = new ReflectionProperty(Container::class, 'autoSingletons');

        config()->set('telescope.storage.database.connection', 'reloaded');
        config()->set('telescope.storage.database.chunk', 250);

        (new TelescopeServiceProvider($this->app))->reloadConfiguration();

        $this->assertSame($entries, $this->app->make(EntriesRepository::class));
        $this->assertSame($clearable, $this->app->make(ClearableRepository::class));
        $this->assertSame($prunable, $this->app->make(PrunableRepository::class));
        $this->assertSame($entries, $store->getValue());

        foreach ([$entries, $clearable, $prunable] as $repository) {
            $this->assertInstanceOf(DatabaseEntriesRepository::class, $repository);
            $this->assertSame('reloaded', $connection->getValue($repository));
            $this->assertSame(250, $chunkSize->getValue($repository));
        }

        $this->assertArrayNotHasKey(DatabaseEntriesRepository::class, $instances->getValue($this->app));
        $this->assertArrayNotHasKey(DatabaseEntriesRepository::class, $autoSingletons->getValue($this->app));
    }

    public function testReloadConfigurationDoesNotResolveUnusedOrReplacedRepositories(): void
    {
        $app = m::mock(ApplicationContract::class);
        $config = m::mock(ConfigRepository::class);
        $repository = m::mock(EntriesRepository::class);
        $app->shouldReceive('resolved')->once()->with(EntriesRepository::class)->andReturnTrue();
        $app->shouldReceive('resolved')->once()->with(ClearableRepository::class)->andReturnFalse();
        $app->shouldReceive('resolved')->once()->with(PrunableRepository::class)->andReturnFalse();
        $app->shouldReceive('make')->once()->with(ConfigRepository::class)->andReturn($config);
        $app->shouldReceive('make')->once()->with(EntriesRepository::class)->andReturn($repository);
        $app->shouldNotReceive('make')->with(DatabaseEntriesRepository::class);

        (new TelescopeServiceProvider($app))->reloadConfiguration();
    }
}
