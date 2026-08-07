<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Container\Container;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Testing\Concerns\InteractsWithRedis;
use Hypervel\Support\Env;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\ParallelTesting;
use RuntimeException;

class InteractsWithRedisParallelTest extends TestCase
{
    /**
     * Redis-related environment keys mutated by these tests.
     *
     * @var list<string>
     */
    private const REDIS_ENVIRONMENT_KEYS = [
        'REDIS_HOST',
        'REDIS_DB',
        'REDIS_TEST_DB_MIN',
        'REDIS_TEST_DB_MAX',
        'REDIS_TEST_SECONDARY_DB',
    ];

    /**
     * Original environment values.
     *
     * @var array<string, array{process: false|string, server_exists: bool, server: mixed, environment_exists: bool, environment: mixed}>
     */
    private array $originalEnvironmentValues = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->captureEnvironmentValues();
        $this->setParallelTestingToken(false);
    }

    protected function tearDown(): void
    {
        $this->app->make(ParallelTesting::class)->resolveTokenUsing(null);
        $this->restoreEnvironmentValues();

        parent::tearDown();
    }

    public function testGetBaseRedisDbReturnsRedisDb(): void
    {
        $this->setRedisEnvironmentValue('REDIS_DB', '3');

        $this->assertSame(3, $this->harness()->baseRedisDb());
    }

    public function testGetBaseRedisDbDefaultsToZero(): void
    {
        $this->setRedisEnvironmentValue('REDIS_DB', null);

        $this->assertSame(0, $this->harness()->baseRedisDb());
    }

    public function testGetParallelRedisDbReturnsBaseWhenNotParallel(): void
    {
        $this->setRedisEnvironmentValue('REDIS_DB', '4');

        $this->assertSame(4, $this->harness()->parallelRedisDb());
    }

    public function testParallelWorkerUsesConfiguredMinimumRange(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', '4');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MAX', '8');
        $this->setParallelTestingToken('3');

        $this->assertSame(6, $this->harness()->parallelRedisDb());
    }

    public function testRedisTestDbMinDefaultsToRedisDb(): void
    {
        $this->setRedisEnvironmentValue('REDIS_DB', '4');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', null);
        $this->setParallelTestingToken('2');

        $this->assertSame(5, $this->harness()->parallelRedisDb());
    }

    public function testRedisTestDbMaxDefaultsToFifteen(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', '14');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MAX', null);
        $this->setRedisEnvironmentValue('REDIS_TEST_SECONDARY_DB', null);

        $this->assertSame([14, 15], $this->harness()->workerDatabases());
    }

    public function testParallelWorkerOverflowFails(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', '1');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MAX', '2');
        $this->setParallelTestingToken('3');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Parallel Redis worker [3] has no configured Redis database.');

        $this->harness()->parallelRedisDb();
    }

    public function testSecondaryRedisDbIsRequired(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_SECONDARY_DB', null);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REDIS_TEST_SECONDARY_DB must be set before requesting the secondary Redis test database.');

        $this->harness()->secondaryRedisDb();
    }

    public function testConfiguredSecondaryRedisDbIsReturned(): void
    {
        $this->setRedisEnvironmentValue('REDIS_DB', '1');
        $this->setRedisEnvironmentValue('REDIS_TEST_SECONDARY_DB', '9');

        $this->assertSame(9, $this->harness()->secondaryRedisDb());
    }

    public function testConfiguredSecondaryRedisDbIsSkippedDuringWorkerAllocation(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', '1');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MAX', '4');
        $this->setRedisEnvironmentValue('REDIS_TEST_SECONDARY_DB', '2');

        $this->setParallelTestingToken('1');
        $this->assertSame(1, $this->harness()->parallelRedisDb());

        $this->setParallelTestingToken('2');
        $this->assertSame(3, $this->harness()->parallelRedisDb());

        $this->setParallelTestingToken('3');
        $this->assertSame(4, $this->harness()->parallelRedisDb());
    }

    public function testSecondaryRedisDbMatchingPrimaryFails(): void
    {
        $this->setRedisEnvironmentValue('REDIS_DB', '2');
        $this->setRedisEnvironmentValue('REDIS_TEST_SECONDARY_DB', '2');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REDIS_TEST_SECONDARY_DB must be different from the current Redis test database.');

        $this->harness()->secondaryRedisDb();
    }

    public function testInvalidRedisTestDbRangeFails(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', '5');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MAX', '4');
        $this->setParallelTestingToken('1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REDIS_TEST_DB_MAX must be greater than or equal to REDIS_TEST_DB_MIN.');

        $this->harness()->parallelRedisDb();
    }

    public function testInvalidRedisEnvironmentValueFails(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', 'not-a-number');
        $this->setParallelTestingToken('1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REDIS_TEST_DB_MIN must be a non-negative integer.');

        $this->harness()->parallelRedisDb();
    }

    public function testInvalidNegativeRedisEnvironmentValueFails(): void
    {
        $this->setRedisEnvironmentValue('REDIS_DB', '-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REDIS_DB must be a non-negative integer.');

        $this->harness()->baseRedisDb();
    }

    public function testInvalidTestTokenFails(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', '1');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MAX', '4');
        $this->setParallelTestingToken('zero');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('TEST_TOKEN must be a positive integer for Redis parallel testing.');

        $this->harness()->parallelRedisDb();
    }

    public function testCustomParallelTestingTokenResolverIsHonored(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', '1');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MAX', '5');
        $previousProcessToken = getenv('TEST_TOKEN');
        $previousServerTokenExists = array_key_exists('TEST_TOKEN', $_SERVER);
        $previousServerToken = $_SERVER['TEST_TOKEN'] ?? null;
        $previousEnvironmentTokenExists = array_key_exists('TEST_TOKEN', $_ENV);
        $previousEnvironmentToken = $_ENV['TEST_TOKEN'] ?? null;

        try {
            putenv('TEST_TOKEN=5');
            $_SERVER['TEST_TOKEN'] = '5';
            $_ENV['TEST_TOKEN'] = '5';
            $this->setParallelTestingToken('3');

            $this->assertSame(3, $this->harness()->parallelRedisDb());
        } finally {
            $previousProcessToken === false
                ? putenv('TEST_TOKEN')
                : putenv("TEST_TOKEN={$previousProcessToken}");

            if ($previousServerTokenExists) {
                $_SERVER['TEST_TOKEN'] = $previousServerToken;
            } else {
                unset($_SERVER['TEST_TOKEN']);
            }

            if ($previousEnvironmentTokenExists) {
                $_ENV['TEST_TOKEN'] = $previousEnvironmentToken;
            } else {
                unset($_ENV['TEST_TOKEN']);
            }
        }
    }

    public function testParallelTestingTokenCanBeResolvedBeforeTheTestCaseAppIsAssigned(): void
    {
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', '1');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MAX', '5');
        $this->setParallelTestingToken('4');

        $previousContainer = Container::getInstance();

        try {
            Container::setInstance($this->app);

            $this->assertSame(4, (new InteractsWithRedisHarness)->parallelRedisDb());
        } finally {
            Container::setInstance($previousContainer);
        }
    }

    public function testSequentialSetupNormalizesEveryConfiguredConnectionToTheBaseDatabase(): void
    {
        $this->setRedisEnvironmentValue('REDIS_HOST', '127.0.0.1');
        $this->setRedisEnvironmentValue('REDIS_DB', '7');
        $config = $this->app->make('config');
        $config->set('database.redis.default.database', 1);
        $config->set('database.redis.cache.database', 2);
        $config->set('database.redis.queue.database', 3);

        $harness = $this->harness();
        $harness->runSetUp();

        $this->assertSame(7, $config->integer('database.redis.default.database'));
        $this->assertSame(7, $config->integer('database.redis.cache.database'));
        $this->assertSame(7, $config->integer('database.redis.queue.database'));
        $this->assertSame(1, $harness->flushRedisCalls);
    }

    public function testParallelSetupNormalizesEveryConfiguredConnectionToTheWorkerDatabase(): void
    {
        $this->setRedisEnvironmentValue('REDIS_HOST', '127.0.0.1');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MIN', '4');
        $this->setRedisEnvironmentValue('REDIS_TEST_DB_MAX', '8');
        $this->setParallelTestingToken('3');
        $config = $this->app->make('config');

        $harness = $this->harness();
        $harness->runSetUp();

        $this->assertSame(6, $config->integer('database.redis.default.database'));
        $this->assertSame(6, $config->integer('database.redis.cache.database'));
        $this->assertSame(6, $config->integer('database.redis.session.database'));
        $this->assertSame(6, $config->integer('database.redis.queue.database'));
        $this->assertSame(6, $config->integer('database.redis.reverb.database'));
    }

    public function testSetupIgnoresReservedAndNonConnectionConfiguration(): void
    {
        $this->setRedisEnvironmentValue('REDIS_HOST', '127.0.0.1');
        $config = $this->app->make('config');
        $config->set('database.redis.client', 'phpredis');
        $config->set('database.redis.clusters', ['enabled' => true]);
        $config->set('database.redis.fixture', 'value');
        $options = $config->array('database.redis.options');

        $this->harness()->runSetUp();

        $this->assertSame('phpredis', $config->string('database.redis.client'));
        $this->assertSame(['enabled' => true], $config->array('database.redis.clusters'));
        $this->assertSame('value', $config->string('database.redis.fixture'));
        $this->assertSame($options, $config->array('database.redis.options'));
    }

    public function testSetupTreatsEmptyConnectionUrlsAsUnset(): void
    {
        $this->setRedisEnvironmentValue('REDIS_HOST', '127.0.0.1');
        $this->setRedisEnvironmentValue('REDIS_DB', '7');
        $config = $this->app->make('config');
        $config->set('database.redis.cache.url', '');
        $config->set('database.redis.cache.database', 2);
        $harness = $this->harness();

        $harness->runSetUp();

        $this->assertSame(7, $config->integer('database.redis.cache.database'));
        $this->assertSame(1, $harness->flushRedisCalls);
    }

    public function testSetupRejectsUrlConfiguredConnections(): void
    {
        $this->setRedisEnvironmentValue('REDIS_HOST', '127.0.0.1');
        $this->app->make('config')->set('database.redis.cache.url', 'redis://127.0.0.1:6379/4');
        $harness = $this->harness();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Redis connection [cache] must use REDIS_HOST and REDIS_PORT during integration tests');

        try {
            $harness->runSetUp();
        } finally {
            $this->assertSame(0, $harness->flushRedisCalls);
        }
    }

    /**
     * Get an InteractsWithRedis harness.
     */
    private function harness(): InteractsWithRedisHarness
    {
        return new InteractsWithRedisHarness($this->app);
    }

    /**
     * Set the parallel testing token resolver.
     */
    private function setParallelTestingToken(string|false $token): void
    {
        $this->app->make(ParallelTesting::class)->resolveTokenUsing(fn () => $token);
    }

    /**
     * Capture original environment values.
     */
    private function captureEnvironmentValues(): void
    {
        foreach (self::REDIS_ENVIRONMENT_KEYS as $key) {
            $this->originalEnvironmentValues[$key] = [
                'process' => getenv($key),
                'server_exists' => array_key_exists($key, $_SERVER),
                'server' => $_SERVER[$key] ?? null,
                'environment_exists' => array_key_exists($key, $_ENV),
                'environment' => $_ENV[$key] ?? null,
            ];
        }
    }

    /**
     * Set a Redis environment value.
     */
    private function setRedisEnvironmentValue(string $key, ?string $value): void
    {
        if ($value === null) {
            putenv($key);
            unset($_SERVER[$key], $_ENV[$key]);
        } else {
            putenv("{$key}={$value}");
            $_SERVER[$key] = $value;
            $_ENV[$key] = $value;
        }

        Env::flushRepository();
    }

    /**
     * Restore original environment values.
     */
    private function restoreEnvironmentValues(): void
    {
        foreach ($this->originalEnvironmentValues as $key => $value) {
            $value['process'] === false
                ? putenv($key)
                : putenv("{$key}={$value['process']}");

            if ($value['server_exists']) {
                $_SERVER[$key] = $value['server'];
            } else {
                unset($_SERVER[$key]);
            }

            if ($value['environment_exists']) {
                $_ENV[$key] = $value['environment'];
            } else {
                unset($_ENV[$key]);
            }
        }

        Env::flushRepository();
    }
}

class InteractsWithRedisHarness
{
    use InteractsWithRedis;

    public int $flushRedisCalls = 0;

    public function __construct(
        protected ?ApplicationContract $app = null
    ) {
    }

    /**
     * Get the base Redis DB number.
     */
    public function baseRedisDb(): int
    {
        return $this->getBaseRedisDb();
    }

    /**
     * Get the current primary Redis DB number.
     */
    public function parallelRedisDb(): int
    {
        return $this->getParallelRedisDb();
    }

    /**
     * Get the secondary Redis DB number.
     */
    public function secondaryRedisDb(): int
    {
        return $this->getSecondaryRedisDb();
    }

    /**
     * Get the configured Redis worker databases.
     *
     * @return array<int, int>
     */
    public function workerDatabases(): array
    {
        return $this->redisWorkerDatabases();
    }

    /**
     * Run Redis setup.
     */
    public function runSetUp(): void
    {
        $this->setUpInteractsWithRedis();
    }

    /**
     * Flush the Redis database.
     */
    protected function flushRedis(): void
    {
        ++$this->flushRedisCalls;
    }
}
