<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class DefaultConfigurationTest extends TestCase
{
    #[Test]
    public function itCanLoadUsingTestbenchConfigurations(): void
    {
        $this->assertSame(\Hypervel\Testbench\Bootstrap\LoadConfiguration::class, \get_class($this->app[LoadConfiguration::class]));
    }

    #[Test]
    public function itPopulatesExpectedDebugConfig(): void
    {
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $this->app['config']['app.debug']);
    }

    #[Test]
    public function itPopulatesExpectedAppKeyConfig(): void
    {
        $this->assertSame('AckfSECXIvnK5r28GVIWUAxmbBSjTsmF', $this->app['config']['app.key']);
    }

    #[Test]
    public function itPopulatesExpectedTestingConfig(): void
    {
        $this->assertEquals([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ], $this->app['config']['database.connections.testing']);

        $this->assertTrue($this->usesSqliteInMemoryDatabaseConnection('testing'));
        $this->assertFalse($this->usesSqliteInMemoryDatabaseConnection('sqlite'));
    }

    #[Test]
    public function itUsesTheCanonicalSqliteMemoryClassification(): void
    {
        $config = $this->app->make('config');
        $config->set('database.connections.uri_memory', [
            'driver' => 'sqlite',
            'database' => 'file::memory:',
        ]);
        $config->set('database.connections.uri_file', [
            'driver' => 'sqlite',
            'database' => 'file:database?mode=memory&mode=rwc',
        ]);

        $this->assertTrue($this->usesSqliteInMemoryDatabaseConnection('uri_memory'));
        $this->assertFalse($this->usesSqliteInMemoryDatabaseConnection('uri_file'));
    }

    #[Test]
    public function itFallsBackToTheTestingConnectionWhenRuntimeSqliteIsMissing(): void
    {
        $sqliteDatabase = $this->app['config']['database.connections.sqlite.database'];

        $this->assertSame('testing', $this->app['config']['database.default']);
        $this->assertSame(BASE_PATH . '/database/database.sqlite', $sqliteDatabase);
        $this->assertFileDoesNotExist($sqliteDatabase);
    }

    #[Test]
    public function itPopulatesExpectedCacheDefaults(): void
    {
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER') ? 'database' : 'array', $this->app['config']['cache.default']);
        $this->assertTrue($this->app['config']->has('cache.limiter'));
        $this->assertNull($this->app['config']['cache.limiter']);
        $this->assertFalse($this->app['config']['cache.serializable_classes']);
    }

    #[Test]
    public function itPopulatesExpectedSessionDefaults(): void
    {
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER') ? 'cookie' : 'array', $this->app['config']['session.driver']);
    }

    #[Test]
    public function itPopulatesExpectedRedisConnections(): void
    {
        $connections = $this->app['config']['database.redis'];

        $this->assertArrayHasKey('default', $connections);
        $this->assertArrayHasKey('cache', $connections);
        $this->assertArrayHasKey('session', $connections);
        $this->assertArrayHasKey('queue', $connections);
        $this->assertArrayHasKey('reverb', $connections);
    }

    #[Test]
    public function itUsesImmutableDatesByDefault(): void
    {
        $date = Date::parse('2023-01-01');

        $this->assertSame(CarbonImmutable::class, $date::class);
    }

    #[Test]
    public function itResolvesTheDefaultUserModel(): void
    {
        $this->assertSame(User::class, $this->app['config']['auth.providers.users.model']);
    }
}
