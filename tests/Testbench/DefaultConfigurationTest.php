<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench;

use Hypervel\Config\Repository;
use Hypervel\Foundation\Auth\User;
use Hypervel\Foundation\Bootstrap\LoadConfiguration;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\Bootstrap\LoadConfiguration as TestbenchLoadConfiguration;
use Hypervel\Testbench\Foundation\Env;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionMethod;

class DefaultConfigurationTest extends TestCase
{
    #[Test]
    public function itCanLoadUsingTestbenchConfigurations(): void
    {
        $this->assertSame(TestbenchLoadConfiguration::class, $this->app->make(LoadConfiguration::class)::class);
    }

    #[Test]
    public function itPopulatesExpectedDebugConfig(): void
    {
        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER'), $this->app->make('config')->boolean('app.debug'));
    }

    #[Test]
    public function itPopulatesExpectedAppKeyConfig(): void
    {
        $this->assertSame('AckfSECXIvnK5r28GVIWUAxmbBSjTsmF', $this->app->make('config')->string('app.key'));
    }

    #[Test]
    public function itPopulatesExpectedTestingConfig(): void
    {
        $this->assertEquals([
            'driver' => 'sqlite',
            'database' => ':memory:',
            'foreign_key_constraints' => false,
        ], $this->app->make('config')->array('database.connections.testing'));

        $this->assertTrue($this->usesSqliteInMemoryDatabaseConnection('testing'));
        $this->assertFalse($this->usesSqliteInMemoryDatabaseConnection('sqlite'));
    }

    #[Test]
    public function itNormalizesTheTestingConnectionForeignKeyEnvironmentValue(): void
    {
        $key = 'DB_FOREIGN_KEYS';
        $hadOriginalValue = Env::has($key);
        $originalValue = Env::get($key);

        Env::forget($key);
        Env::set($key, '0');

        try {
            (new TestbenchLoadConfiguration)->bootstrap($this->app);

            $this->assertFalse(
                $this->app->make('config')->boolean('database.connections.testing.foreign_key_constraints')
            );
        } finally {
            Env::forget($key);

            if ($hadOriginalValue) {
                Env::set($key, (string) Env::encode($originalValue));
            }
        }
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
        $config->set('database.connections.url_memory', [
            'url' => 'sqlite:///:memory:',
        ]);

        $this->assertTrue($this->usesSqliteInMemoryDatabaseConnection('uri_memory'));
        $this->assertFalse($this->usesSqliteInMemoryDatabaseConnection('uri_file'));
        $this->assertTrue($this->usesSqliteInMemoryDatabaseConnection('url_memory'));
    }

    #[Test]
    public function itFallsBackToTheTestingConnectionWhenRuntimeSqliteIsMissing(): void
    {
        $config = $this->app->make('config');
        $sqliteDatabase = $config->string('database.connections.sqlite.database');

        $this->assertSame('testing', $config->string('database.default'));
        $this->assertSame(BASE_PATH . '/database/database.sqlite', $sqliteDatabase);
        $this->assertFileDoesNotExist($sqliteDatabase);
    }

    #[Test]
    #[DataProvider('sqliteNonFileIdentifiers')]
    public function itDoesNotReplaceSqliteMemoryOrUriConnections(string $database): void
    {
        $config = new Repository([
            'database' => [
                'default' => 'sqlite',
                'connections' => [
                    'sqlite' => ['database' => $database],
                ],
            ],
            'queue' => [
                'batching' => ['database' => 'sqlite'],
                'failed' => ['database' => 'sqlite'],
            ],
        ]);
        $method = new ReflectionMethod(TestbenchLoadConfiguration::class, 'configureDefaultDatabaseConnection');

        $method->invoke(new TestbenchLoadConfiguration, $config);

        $this->assertSame('sqlite', $config->get('database.default'));
        $this->assertSame('sqlite', $config->get('queue.batching.database'));
        $this->assertSame('sqlite', $config->get('queue.failed.database'));
    }

    /**
     * Provide SQLite identifiers that do not represent ordinary local files.
     */
    public static function sqliteNonFileIdentifiers(): array
    {
        return [
            'memory' => [':memory:'],
            'memory URI' => ['file::memory:'],
            'file URI' => ['file:/tmp/testbench.sqlite?mode=rwc'],
        ];
    }

    #[Test]
    public function itPopulatesExpectedCacheDefaults(): void
    {
        $config = $this->app->make('config');

        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER') ? 'database' : 'array', $config->string('cache.default'));
        $this->assertFalse($config->boolean('cache.serializable_classes'));
    }

    #[Test]
    public function itPopulatesExpectedRateLimiterDefaults(): void
    {
        $config = $this->app->make('config');

        $this->assertSame('worker-array', $config->string('rate-limiter.default'));
        $this->assertSame(
            ['database', 'redis', 'swoole', 'worker-array'],
            array_keys($config->array('rate-limiter.stores')),
        );
        $this->assertSame(app_id() . '_rate_limiter', $config->string('rate-limiter.prefix'));
    }

    #[Test]
    public function itPopulatesExpectedSessionDefaults(): void
    {
        $config = $this->app->make('config');

        $this->assertSame(Env::has('TESTBENCH_PACKAGE_TESTER') ? 'cookie' : 'array', $config->string('session.driver'));
        $this->assertSame(app_id() . '_session', $config->string('session.cookie'));
    }

    #[Test]
    public function itPopulatesExpectedRedisConnections(): void
    {
        $connections = $this->app->make('config')->array('database.redis');

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
        $this->assertSame(User::class, $this->app->make('config')->string('auth.providers.users.model'));
    }
}
