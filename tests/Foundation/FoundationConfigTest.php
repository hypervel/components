<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;

class FoundationConfigTest extends TestCase
{
    public function testViewCompiledPathFallsBackToStoragePathWhenDirectoryDoesNotExist(): void
    {
        $key = 'VIEW_COMPILED_PATH';
        $originalContainer = Container::getInstance();
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            unset($_SERVER[$key], $_ENV[$key]);
            putenv($key);
            Env::flushRepository();

            $app = new Application(dirname(__DIR__, 2));
            $app->useStoragePath(sys_get_temp_dir() . '/hypervel-view-config-' . bin2hex(random_bytes(8)));
            Container::setInstance($app);

            $compiledPath = $app->storagePath('framework/views');

            $this->assertDirectoryDoesNotExist($compiledPath);

            $config = require dirname(__DIR__, 2) . '/src/foundation/config/view.php';

            $this->assertSame($compiledPath, $config['compiled']);
        } finally {
            Container::setInstance($originalContainer);

            $originalPutenv === false
                ? putenv($key)
                : putenv("{$key}={$originalPutenv}");

            if ($originalServerExists) {
                $_SERVER[$key] = $originalServer;
            } else {
                unset($_SERVER[$key]);
            }

            if ($originalEnvExists) {
                $_ENV[$key] = $originalEnv;
            } else {
                unset($_ENV[$key]);
            }

            Env::flushRepository();
        }
    }
}
