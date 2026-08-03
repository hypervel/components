<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Foundation\Application;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;
use Swoole\Constant;

class FoundationConfigTest extends TestCase
{
    public function testAppConfigReadsForceHttpsFromForceHttpsEnvironmentVariable(): void
    {
        $config = $this->appConfigWithEnvironment('FORCE_HTTPS', 'true');

        $this->assertTrue($config['force_https']);
    }

    public function testAppConfigReadsStdoutLogFormatFromStdoutLogFormatEnvironmentVariable(): void
    {
        $config = $this->appConfigWithEnvironment('STDOUT_LOG_FORMAT', 'json');

        $this->assertSame('json', $config['stdout_log']['format']);
    }

    public function testAppConfigTreatsNullPreviousKeysAsAnEmptyList(): void
    {
        $config = $this->appConfigWithEnvironment('APP_PREVIOUS_KEYS', '(null)');

        $this->assertSame([], $config['previous_keys']);
    }

    public function testServerConfigUsesSafeTaskDefaults(): void
    {
        $originalContainer = Container::getInstance();

        try {
            new Application(dirname(__DIR__, 2));

            $config = require dirname(__DIR__, 2) . '/src/foundation/config/server.php';
        } finally {
            Container::setInstance($originalContainer);
        }

        $this->assertFalse($config['settings'][Constant::OPTION_TASK_ENABLE_COROUTINE]);
        $this->assertSame(0, $config['settings'][Constant::OPTION_TASK_WORKER_NUM]);
        $this->assertFalse($config['settings'][Constant::OPTION_DAEMONIZE]);
    }

    public function testReverbBroadcastingConfigUsesTheServerPath(): void
    {
        $config = $this->withEnvironmentValue('REVERB_SERVER_PATH', '/socket', function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/broadcasting.php';
        });

        $this->assertSame('/socket', $config['connections']['reverb']['options']['path']);
    }

    public function testBroadcastingConfigDisablesJsonpAndDoesNotShipSdkPools(): void
    {
        $config = require dirname(__DIR__, 2) . '/src/foundation/config/broadcasting.php';

        $this->assertFalse($config['connections']['reverb']['jsonp']);
        $this->assertFalse($config['connections']['pusher']['jsonp']);
        $this->assertArrayNotHasKey('pool', $config['connections']['pusher']);
        $this->assertArrayNotHasKey('pool', $config['connections']['ably']);
    }

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

    /**
     * Load the application config with one environment override.
     */
    protected function appConfigWithEnvironment(string $key, string $value): array
    {
        return $this->withEnvironmentValue($key, $value, function (): array {
            return require dirname(__DIR__, 2) . '/src/foundation/config/app.php';
        });
    }

    /**
     * Run a callback with a temporary environment variable value.
     */
    protected function withEnvironmentValue(string $key, string $value, Closure $callback): mixed
    {
        $originalPutenv = getenv($key);
        $originalServerExists = array_key_exists($key, $_SERVER);
        $originalServer = $_SERVER[$key] ?? null;
        $originalEnvExists = array_key_exists($key, $_ENV);
        $originalEnv = $_ENV[$key] ?? null;

        try {
            $this->setEnvironmentValue($key, $value);

            return $callback();
        } finally {
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
