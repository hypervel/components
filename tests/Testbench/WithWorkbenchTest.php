<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use Hypervel\Testbench\Foundation\Config;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Testbench\Workbench\Workbench;
use Hypervel\Tests\Testbench\Fixtures\MergeSeedersTestStub;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class WithWorkbenchTest extends TestCase
{
    use WithWorkbench;

    #[Test]
    public function itCanBeResolved()
    {
        $cachedConfig = Workbench::configuration();

        $this->assertInstanceOf(ConfigContract::class, $cachedConfig);

        $this->assertSame($cachedConfig, static::cachedConfigurationForWorkbench());

        $this->assertSame([
            'env' => ["APP_NAME='Testbench'"],
            'bootstrappers' => [],
            'providers' => ['Workbench\App\Providers\WorkbenchServiceProvider'],
            'dont-discover' => [],
        ], $cachedConfig->getExtraAttributes());
    }

    #[Test]
    public function itCanBeManuallyResolved()
    {
        $cachedConfig = static::cachedConfigurationForWorkbench();

        Workbench::flush();

        $config = static::cachedConfigurationForWorkbench();

        $this->assertInstanceOf(ConfigContract::class, $config);

        $this->assertSame($cachedConfig->toArray(), $config->toArray());
    }

    #[Test]
    public function itCanAutoDetectPackagesViaBootstrapProvidersFile()
    {
        $loadedProviders = collect($this->app->getLoadedProviders())->keys()->all();

        $this->assertContains('Workbench\App\Providers\AppServiceProvider', $loadedProviders);
    }

    #[Test]
    public function itCanResolveUserModelFromWorkbench()
    {
        $this->assertFalse(Env::has('AUTH_MODEL'));
        $this->assertSame('Workbench\App\Models\User', config('auth.providers.users.model'));
    }

    #[Test]
    #[DataProvider('seedersDataProvider')]
    public function itCanMergeSeedersWithHypervelDatabaseRefresh(
        bool $seed,
        string|false $seeder,
        array|false $workbenchSeeders,
        array|false $expected
    ) {
        $stub = new MergeSeedersTestStub($seed, $seeder);

        $config = new Config(['seeders' => $workbenchSeeders]);

        $this->assertSame($expected, $stub($config));
    }

    public static function seedersDataProvider()
    {
        yield [false, false, ['Workbench\Database\Seeders\DatabaseSeeder'], false];
        yield [true, false, ['Workbench\Database\Seeders\DatabaseSeeder'], ['Workbench\Database\Seeders\DatabaseSeeder']];
        yield [true, 'Database\Seeders\DatabaseSeeder', ['Workbench\Database\Seeders\DatabaseSeeder'], ['Workbench\Database\Seeders\DatabaseSeeder']];
        yield [false, 'Database\Seeders\DatabaseSeeder', ['Workbench\Database\Seeders\DatabaseSeeder'], false];
        yield [true, 'Database\Seeders\DatabaseSeeder', ['Database\Seeders\DatabaseSeeder', 'Workbench\Database\Seeders\DatabaseSeeder'], ['Workbench\Database\Seeders\DatabaseSeeder']];
        yield [true, 'Workbench\Database\Seeders\DatabaseSeeder', ['Workbench\Database\Seeders\DatabaseSeeder'], false];
    }
}
