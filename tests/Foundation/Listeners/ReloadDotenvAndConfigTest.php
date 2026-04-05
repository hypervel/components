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

/**
 * @internal
 * @coversNothing
 */
class ReloadDotenvAndConfigTest extends TestCase
{
    protected ?string $originalAppName = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalAppName = getenv('APP_NAME') ?: null;

        DotenvManager::flushState();
        Env::flushState();
        ReloadDotenvAndConfig::flushState();
    }

    protected function tearDown(): void
    {
        DotenvManager::flushState();
        Env::flushState();
        ReloadDotenvAndConfig::flushState();
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
        $listener = new ReloadDotenvAndConfig($app);
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
        $listener = new ReloadDotenvAndConfig($app);
        $listener->handle($event);

        // Values should still be from the original .env since reload was skipped.
        $this->assertSame('Hypervel', Env::get('APP_NAME'));
    }

    public function testReloadConfigRebuildsARealRepositoryAndReplaysRuntimeMutations()
    {
        $app = $this->createApp();
        $listener = new ReloadDotenvAndConfig($app);
        $originalConfig = $app->make(Repository::class);

        $originalConfig->set('app.name', 'Reloaded Hypervel');

        $listener->handle(m::mock(BeforeWorkerStart::class));

        $reloadedConfig = $app->make(Repository::class);

        $this->assertInstanceOf(Repository::class, $reloadedConfig);
        $this->assertNotInstanceOf(ConfigFacade::class, $reloadedConfig);
        $this->assertNotSame($originalConfig, $reloadedConfig);
        $this->assertSame('Reloaded Hypervel', $reloadedConfig->get('app.name'));
    }

    protected function createApp(): Application
    {
        $app = new Application(__DIR__ . '/../Fixtures/envs');

        (new LoadConfiguration())->bootstrap($app);

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
