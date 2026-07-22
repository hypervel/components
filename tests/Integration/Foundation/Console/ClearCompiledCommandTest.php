<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Foundation\Console;

use Hypervel\Support\Env;
use Hypervel\Testbench\TestCase;
use RuntimeException;

class ClearCompiledCommandTest extends TestCase
{
    public function testDeletesCachedPackagesFile(): void
    {
        $path = $this->app->getCachedPackagesPath();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($path, '<?php return [];');
        $this->assertFileExists($path);

        $this->artisan('clear-compiled')->assertSuccessful();

        $this->assertFileDoesNotExist($path);
    }

    public function testSucceedsWhenNoFilesExist(): void
    {
        @unlink($this->app->getCachedPackagesPath());

        $this->artisan('clear-compiled')->assertSuccessful();
    }

    public function testFailsWhenCachedPackagesFileRemains(): void
    {
        $scheme = 'hypervel-clear-compiled';
        $path = $scheme . '://packages.php';
        $previousPath = Env::get('APP_PACKAGES_CACHE');

        $this->assertTrue(stream_wrapper_register($scheme, UndeletableCompiledPackageStreamWrapper::class));
        $this->app->addAbsoluteCachePathPrefix($scheme . '://');
        Env::getRepository()->set('APP_PACKAGES_CACHE', $path);

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage("Unable to delete the compiled packages file [{$path}].");

            $this->artisan('clear-compiled');
        } finally {
            Env::deleteMany(['APP_PACKAGES_CACHE']);
            Env::flushRepository();

            if ($previousPath !== null) {
                Env::getRepository()->set('APP_PACKAGES_CACHE', $previousPath);
            }

            stream_wrapper_unregister($scheme);
        }
    }

    public function testSucceedsWhenCachedPackagesFileDisappearsDuringDeletion(): void
    {
        $scheme = 'hypervel-clear-compiled-vanishing';
        $path = $scheme . '://packages.php';
        $previousPath = Env::get('APP_PACKAGES_CACHE');
        VanishingCompiledPackageStreamWrapper::$deleted = false;

        $this->assertTrue(stream_wrapper_register($scheme, VanishingCompiledPackageStreamWrapper::class));
        $this->app->addAbsoluteCachePathPrefix($scheme . '://');
        Env::getRepository()->set('APP_PACKAGES_CACHE', $path);

        try {
            $this->artisan('clear-compiled')
                ->expectsOutputToContain('Compiled packages file removed successfully.')
                ->assertSuccessful();
        } finally {
            Env::deleteMany(['APP_PACKAGES_CACHE']);
            Env::flushRepository();

            if ($previousPath !== null) {
                Env::getRepository()->set('APP_PACKAGES_CACHE', $previousPath);
            }

            stream_wrapper_unregister($scheme);
            VanishingCompiledPackageStreamWrapper::$deleted = false;
        }
    }
}

class UndeletableCompiledPackageStreamWrapper
{
    public mixed $context;

    public function unlink(string $path): bool
    {
        return false;
    }

    public function url_stat(string $path, int $flags): array
    {
        return [
            2 => 0100444,
            7 => 1,
            'mode' => 0100444,
            'size' => 1,
        ];
    }
}

class VanishingCompiledPackageStreamWrapper
{
    public mixed $context;

    public static bool $deleted = false;

    public function unlink(string $path): bool
    {
        static::$deleted = true;

        return false;
    }

    public function url_stat(string $path, int $flags): array|false
    {
        if (static::$deleted) {
            return false;
        }

        return [
            2 => 0100444,
            7 => 1,
            'mode' => 0100444,
            'size' => 1,
        ];
    }
}
