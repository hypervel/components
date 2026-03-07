<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Listeners;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Listeners\ReloadDotenvAndConfig;
use Hypervel\Framework\Events\BeforeWorkerStart;
use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class ReloadDotenvAndConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DotenvManager::reset();
        Env::flushState();
    }

    protected function tearDown(): void
    {
        DotenvManager::reset();
        Env::flushState();

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

    protected function createApp(): Application
    {
        $app = new Application(__DIR__ . '/../Bootstrap/envs');
        $app->instance('config', new Repository([]));

        return $app;
    }
}
