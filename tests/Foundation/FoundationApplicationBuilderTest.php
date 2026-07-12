<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;

class FoundationApplicationBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['HYPERVEL_STORAGE_PATH'], $_SERVER['HYPERVEL_STORAGE_PATH']);
        $this->unsetEnvironmentValue('APP_BASE_PATH');

        parent::tearDown();
    }

    public function testBaseDirectoryWithArg(): void
    {
        $_ENV['APP_BASE_PATH'] = __DIR__ . '/as-env';

        $app = Application::configure(__DIR__ . '/as-arg')->create();

        $this->assertSame(__DIR__ . '/as-arg', $app->basePath());
    }

    public function testBaseDirectoryWithEnv(): void
    {
        $_ENV['APP_BASE_PATH'] = __DIR__ . '/as-env';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/as-env', $app->basePath());
    }

    public function testBaseDirectoryWithEnvironmentRepository(): void
    {
        $this->setEnvironmentValue('APP_BASE_PATH', __DIR__ . '/as-env-repository');

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/as-env-repository', $app->basePath());
    }

    public function testBaseDirectoryPrefersEnvOverEnvironmentRepository(): void
    {
        $this->setEnvironmentValue('APP_BASE_PATH', __DIR__ . '/as-env-repository');
        $_ENV['APP_BASE_PATH'] = __DIR__ . '/as-env';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/as-env', $app->basePath());
    }

    public function testBaseDirectoryWithComposer(): void
    {
        $app = Application::configure()->create();

        $this->assertSame(dirname(__DIR__, 2), $app->basePath());
    }

    public function testStoragePathWithGlobalEnvVariable(): void
    {
        $_ENV['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/env-storage';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/env-storage', $app->storagePath());
    }

    public function testStoragePathWithGlobalServerVariable(): void
    {
        $_SERVER['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/server-storage';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/server-storage', $app->storagePath());
    }

    public function testStoragePathPrefersEnvVariable(): void
    {
        $_ENV['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/env-storage';
        $_SERVER['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/server-storage';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/env-storage', $app->storagePath());
    }

    public function testStoragePathBasedOnBasePath(): void
    {
        $app = Application::configure()->create();
        $this->assertSame($app->basePath() . DIRECTORY_SEPARATOR . 'storage', $app->storagePath());
    }

    public function testStoragePathCanBeCustomized(): void
    {
        $_ENV['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/env-storage';

        $app = Application::configure()->create();
        $app->useStoragePath(__DIR__ . '/custom-storage');

        $this->assertSame(__DIR__ . '/custom-storage', $app->storagePath());
    }
}
