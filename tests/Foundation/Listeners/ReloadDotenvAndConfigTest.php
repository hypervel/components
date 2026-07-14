<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Listeners;

use Hypervel\Config\Repository;
use Hypervel\Core\Events\BeforeWorkerStart;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Foundation\Listeners\ReloadDotenvAndConfig;
use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Hypervel\Support\Facades\Config as ConfigFacade;
use Hypervel\Tests\TestCase;
use Mockery as m;

class ReloadDotenvAndConfigTest extends TestCase
{
    protected ?string $originalAppName = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAppName = getenv('APP_NAME') ?: null;

        DotenvManager::flushState();
        Env::flushState();
    }

    protected function tearDown(): void
    {
        DotenvManager::flushState();
        Env::flushState();
        $this->restoreAppName();

        parent::tearDown();
    }

    public function testReloadsUsingApplicationEnvironmentFile()
    {
        $app = $this->createApp();

        // Initial load with default .env.
        DotenvManager::load([$app->environmentPath()]);
        $this->assertSame('Hypervel', Env::get('APP_NAME'));

        // Switch to .env.testing (simulates LoadEnvironmentVariables having selected it).
        $app->loadEnvironmentFrom('.env.testing');

        $event = m::mock(BeforeWorkerStart::class);
        $listener = $app->make(ReloadDotenvAndConfig::class);
        $listener->handle($event);

        // After reload, values should come from .env.testing.
        $this->assertSame('HypervelTesting', Env::get('APP_NAME'));
        $this->assertSame('testing_value', Env::get('TEST_KEY'));
    }

    public function testSkipsReloadWhenEnvironmentFileDoesNotExist()
    {
        $app = $this->createApp();
        $app->loadEnvironmentFrom('.env.nonexistent');

        // Initial load with default .env so there's something cached.
        DotenvManager::load([$app->environmentPath()]);
        $this->assertSame('Hypervel', Env::get('APP_NAME'));

        $event = m::mock(BeforeWorkerStart::class);
        $listener = $app->make(ReloadDotenvAndConfig::class);
        $listener->handle($event);

        // Values should still be from the original .env since reload was skipped.
        $this->assertSame('Hypervel', Env::get('APP_NAME'));
    }

    public function testReloadPreservesRepositoryIdentityAndMutationsMadeBeforeListenerResolution(): void
    {
        $app = $this->createApp();
        $originalConfig = $app->make(Repository::class);

        $originalConfig->set('app.name', 'Reloaded Hypervel');

        $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));

        $reloadedConfig = $app->make(Repository::class);

        $this->assertInstanceOf(Repository::class, $reloadedConfig);
        $this->assertNotInstanceOf(ConfigFacade::class, $reloadedConfig);
        $this->assertSame($originalConfig, $reloadedConfig);
        $this->assertSame('Reloaded Hypervel', $reloadedConfig->get('app.name'));
    }

    public function testReloadReplaysOverlappingMutationsInTheirOriginalOrder(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);

        $config->set('app', ['name' => 'First']);
        $config->set('app.name', 'Second');
        $config->set('app', ['name' => 'Last']);

        $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame(['name' => 'Last'], $config->get('app'));
    }

    public function testReloadPreservesUntouchedSiblingsWhenReplayingAChildMutation(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);
        $environment = $config->get('app.env');

        $config->set('app.name', 'Reloaded Hypervel');

        $app->make(ReloadDotenvAndConfig::class)->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame('Reloaded Hypervel', $config->get('app.name'));
        $this->assertSame($environment, $config->get('app.env'));
    }

    public function testTrackerSealsAfterReplayAndDoesNotRecordLaterMutations(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);
        $listener = $app->make(ReloadDotenvAndConfig::class);

        $config->set('app.name', 'Boot Mutation');
        $listener->handle(m::mock(BeforeWorkerStart::class));

        $config->set('app.name', 'Post Start Mutation');
        $listener->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame('Boot Mutation', $config->get('app.name'));
    }

    public function testTrackerSealsWhenThereAreNoBootMutations(): void
    {
        $app = $this->createApp();
        $config = $app->make(Repository::class);
        $listener = $app->make(ReloadDotenvAndConfig::class);
        $originalName = $config->get('app.name');

        $listener->handle(m::mock(BeforeWorkerStart::class));
        $config->set('app.name', 'Post Start Mutation');
        $listener->handle(m::mock(BeforeWorkerStart::class));

        $this->assertSame($originalName, $config->get('app.name'));
    }

    protected function createApp(): Application
    {
        $app = new Application(__DIR__ . '/../Fixtures/envs');

        (new LoadConfiguration)->bootstrap($app);

        return $app;
    }

    protected function restoreAppName(): void
    {
        if ($this->originalAppName === null) {
            putenv('APP_NAME');
            unset($_ENV['APP_NAME'], $_SERVER['APP_NAME']);

            return;
        }

        putenv("APP_NAME={$this->originalAppName}");
        $_ENV['APP_NAME'] = $this->originalAppName;
        $_SERVER['APP_NAME'] = $this->originalAppName;
    }
}
