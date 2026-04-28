<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\Application;
use Hypervel\Tests\TestCase;

class FoundationApplicationBuilderTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_ENV['APP_BASE_PATH'], $_ENV['HYPERVEL_STORAGE_PATH'], $_SERVER['HYPERVEL_STORAGE_PATH']);

        parent::tearDown();
    }

    public function testBaseDirectoryWithArg()
    {
        $_ENV['APP_BASE_PATH'] = __DIR__ . '/as-env';

        $app = Application::configure(__DIR__ . '/as-arg')->create();

        $this->assertSame(__DIR__ . '/as-arg', $app->basePath());
    }

    public function testBaseDirectoryWithEnv()
    {
        $_ENV['APP_BASE_PATH'] = __DIR__ . '/as-env';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/as-env', $app->basePath());
    }

    public function testBaseDirectoryWithComposer()
    {
        $app = Application::configure()->create();

        $this->assertSame(dirname(__DIR__, 2), $app->basePath());
    }

    public function testStoragePathWithGlobalEnvVariable()
    {
        $_ENV['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/env-storage';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/env-storage', $app->storagePath());
    }

    public function testStoragePathWithGlobalServerVariable()
    {
        $_SERVER['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/server-storage';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/server-storage', $app->storagePath());
    }

    public function testStoragePathPrefersEnvVariable()
    {
        $_ENV['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/env-storage';
        $_SERVER['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/server-storage';

        $app = Application::configure()->create();

        $this->assertSame(__DIR__ . '/env-storage', $app->storagePath());
    }

    public function testStoragePathBasedOnBasePath()
    {
        $app = Application::configure()->create();
        $this->assertSame($app->basePath() . DIRECTORY_SEPARATOR . 'storage', $app->storagePath());
    }

    public function testStoragePathCanBeCustomized()
    {
        $_ENV['HYPERVEL_STORAGE_PATH'] = __DIR__ . '/env-storage';

        $app = Application::configure()->create();
        $app->useStoragePath(__DIR__ . '/custom-storage');

        $this->assertSame(__DIR__ . '/custom-storage', $app->storagePath());
    }
}
