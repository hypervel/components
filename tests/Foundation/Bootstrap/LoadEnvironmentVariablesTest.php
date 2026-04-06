<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Dotenv\Exception\InvalidFileException;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\LoadEnvironmentVariables;
use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class LoadEnvironmentVariablesTest extends TestCase
{
    private string|false $originalAppEnvPutenv;

    private mixed $originalAppEnvServer;

    private mixed $originalAppEnvEnv;

    private array $originalArgv;

    protected function setUp(): void
    {
        parent::setUp();

        // Save original state.
        $this->originalAppEnvPutenv = getenv('APP_ENV');
        $this->originalAppEnvServer = $_SERVER['APP_ENV'] ?? null;
        $this->originalAppEnvEnv = $_ENV['APP_ENV'] ?? null;
        $this->originalArgv = $_SERVER['argv'] ?? [];

        // Clear APP_ENV so the bootstrapper starts clean.
        unset($_SERVER['APP_ENV'], $_ENV['APP_ENV']);
        putenv('APP_ENV');

        DotenvManager::flushState();
        Env::flushState();
    }

    protected function tearDown(): void
    {
        unset($_ENV['FOO'], $_SERVER['FOO']);
        putenv('FOO');

        DotenvManager::flushState();
        Env::flushState();

        // Restore original APP_ENV state.
        if ($this->originalAppEnvPutenv !== false) {
            putenv('APP_ENV=' . $this->originalAppEnvPutenv);
        } else {
            putenv('APP_ENV');
        }

        if ($this->originalAppEnvServer !== null) {
            $_SERVER['APP_ENV'] = $this->originalAppEnvServer;
        } else {
            unset($_SERVER['APP_ENV']);
        }

        if ($this->originalAppEnvEnv !== null) {
            $_ENV['APP_ENV'] = $this->originalAppEnvEnv;
        } else {
            unset($_ENV['APP_ENV']);
        }

        $_SERVER['argv'] = $this->originalArgv;

        parent::tearDown();
    }

    public function testCanLoad()
    {
        $this->expectOutputString('');

        $app = new Application();
        $app->useEnvironmentPath(__DIR__ . '/../Fixtures/envs');

        (new LoadEnvironmentVariables())->bootstrap($app);

        $this->assertSame('BAR', env('FOO'));
        $this->assertSame('BAR', getenv('FOO'));
        $this->assertSame('BAR', $_ENV['FOO']);
        $this->assertSame('BAR', $_SERVER['FOO']);
    }

    public function testCanFailSilent()
    {
        $this->expectOutputString('');

        $app = new Application();
        $app->useEnvironmentPath(__DIR__ . '/../Fixtures/envs');
        $app->loadEnvironmentFrom('BAD_FILE');

        (new LoadEnvironmentVariables())->bootstrap($app);
    }

    public function testLoadsDefaultEnvFile()
    {
        $app = $this->createApp();

        (new LoadEnvironmentVariables())->bootstrap($app);

        $this->assertSame('Hypervel', env('APP_NAME'));
        $this->assertSame('default_value', env('TEST_KEY'));
    }

    public function testSkipsWhenConfigIsCached()
    {
        $tempDir = ParallelTesting::tempDir('LoadEnvVarsTest');
        $filesystem = new Filesystem();

        // Copy fixture .env files to a temp dir so getCachedConfigPath()
        // writes to temp instead of the test source tree.
        $filesystem->copyDirectory(__DIR__ . '/../Fixtures/envs', $tempDir);

        try {
            $app = new Application($tempDir);

            // Create a fake cached config file so configurationIsCached() returns true.
            $cachePath = $app->getCachedConfigPath();
            $cacheDir = dirname($cachePath);

            if (! is_dir($cacheDir)) {
                mkdir($cacheDir, 0755, true);
            }

            file_put_contents($cachePath, '<?php return [];');

            (new LoadEnvironmentVariables())->bootstrap($app);

            // Env vars should NOT be loaded since config is cached.
            $this->assertNull(env('APP_NAME'));
            $this->assertNull(env('TEST_KEY'));
        } finally {
            $filesystem->deleteDirectory($tempDir);
        }
    }

    public function testLoadsPerEnvironmentFile()
    {
        // Set APP_ENV so the bootstrapper finds .env.testing.
        $_SERVER['APP_ENV'] = 'testing';
        $_ENV['APP_ENV'] = 'testing';
        putenv('APP_ENV=testing');

        $app = $this->createApp();

        (new LoadEnvironmentVariables())->bootstrap($app);

        $this->assertSame('HypervelTesting', env('APP_NAME'));
        $this->assertSame('testing_value', env('TEST_KEY'));
    }

    public function testFallsBackToDefaultWhenPerEnvironmentFileMissing()
    {
        $_SERVER['APP_ENV'] = 'nonexistent';
        $_ENV['APP_ENV'] = 'nonexistent';
        putenv('APP_ENV=nonexistent');

        $app = $this->createApp();

        (new LoadEnvironmentVariables())->bootstrap($app);

        // .env.nonexistent doesn't exist, so falls back to .env.
        $this->assertSame('Hypervel', env('APP_NAME'));
        $this->assertSame('default_value', env('TEST_KEY'));
    }

    public function testPopulatesDotenvManagerCachedValues()
    {
        $app = $this->createApp();

        (new LoadEnvironmentVariables())->bootstrap($app);

        // After bootstrap, DotenvManager should track loaded keys
        // so reload() can clean them up. Verify by reloading with a
        // different env file and checking the old keys are gone.
        DotenvManager::reload(
            [__DIR__ . '/../Fixtures/envs'],
            '.env.testing'
        );

        $this->assertSame('HypervelTesting', env('APP_NAME'));
        $this->assertSame('testing_value', env('TEST_KEY'));
    }

    public function testNoErrorWhenEnvFileMissing()
    {
        $app = new Application(__DIR__ . '/../Fixtures/envs/nonexistent');

        // Should not throw — safeLoad handles missing files gracefully.
        (new LoadEnvironmentVariables())->bootstrap($app);

        $this->assertNull(env('APP_NAME'));
    }

    public function testEnvCliOptionOverridesEnvironmentFile()
    {
        // Simulate `php artisan --env=testing`.
        $_SERVER['argv'] = ['artisan', '--env=testing'];

        $app = $this->createApp();
        $app->setRunningInConsole(true);

        (new LoadEnvironmentVariables())->bootstrap($app);

        $this->assertSame('HypervelTesting', env('APP_NAME'));
        $this->assertSame('testing_value', env('TEST_KEY'));
        $this->assertSame('.env.testing', $app->environmentFile());
    }

    public function testMalformedEnvFileTriggersErrorHandler()
    {
        $app = $this->createApp();
        $app->loadEnvironmentFrom('.env.malformed');

        $caughtException = null;

        // Use a subclass that captures the exception instead of exit(1).
        $bootstrapper = new class extends LoadEnvironmentVariables {
            public ?InvalidFileException $caughtException = null;

            protected function writeErrorAndDie(InvalidFileException $e): never
            {
                $this->caughtException = $e;

                // Throw to break out instead of exit(1).
                throw $e;
            }
        };

        try {
            $bootstrapper->bootstrap($app);
        } catch (InvalidFileException $e) {
            $caughtException = $e;
        }

        $this->assertNotNull($caughtException);
        $this->assertSame($caughtException, $bootstrapper->caughtException);
    }

    protected function createApp(): Application
    {
        return new Application(__DIR__ . '/../Fixtures/envs');
    }
}
