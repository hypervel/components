<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use RuntimeException;

class EnvTest extends TestCase
{
    private const ARRAY_KEY = 'TEST_ENV_ARRAY';

    private const REQUIRED_KEY = 'TEST_REQUIRED_ENV';

    protected function setUp(): void
    {
        parent::setUp();

        DotenvManager::flushState();
    }

    protected function tearDown(): void
    {
        DotenvManager::flushState();

        foreach ([self::ARRAY_KEY, self::REQUIRED_KEY] as $key) {
            $this->unsetEnvironmentValue($key);
        }

        Env::flushState();

        parent::tearDown();
    }

    public function testGetReturnsValue()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        $this->assertSame('1.0', Env::get('TEST_VERSION'));
    }

    public function testGetReturnsDefaultWhenKeyMissing()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        $this->assertNull(Env::get('NONEXISTENT'));
        $this->assertSame('default', Env::get('NONEXISTENT', 'default'));
    }

    public function testGetOrFailThrowsWhenKeyMissing()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Environment variable [NONEXISTENT] has no value.');

        Env::getOrFail('NONEXISTENT');
    }

    public function testGetOrFailReturnsValueWhenKeyExists()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        $this->assertSame('1.0', Env::getOrFail('TEST_VERSION'));
    }

    public function testGlobalEnvOrFailReturnsValueWhenKeyExists(): void
    {
        $this->setEnvironmentValue(self::REQUIRED_KEY, 'present');

        $this->assertSame('present', env_or_fail(self::REQUIRED_KEY));
    }

    public function testGlobalEnvOrFailThrowsWhenKeyMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Environment variable [TEST_REQUIRED_ENV] has no value.');

        env_or_fail(self::REQUIRED_KEY);
    }

    public function testGetArrayReturnsDefaultWhenKeyMissing(): void
    {
        $this->assertSame([], Env::getArray(self::ARRAY_KEY));
        $this->assertSame(['default1', 'default2'], Env::getArray(self::ARRAY_KEY, ['default1', 'default2']));
    }

    public function testGetArrayReturnsDefaultWhenKeyIsEmptyString(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, '');

        $this->assertSame(['fallback'], Env::getArray(self::ARRAY_KEY, ['fallback']));
    }

    public function testGetArrayReturnsDefaultForNullAndEmptySentinels(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, '(null)');
        $this->assertSame(['fallback'], Env::getArray(self::ARRAY_KEY, ['fallback']));

        $this->setEnvironmentValue(self::ARRAY_KEY, '(empty)');
        $this->assertSame(['fallback'], Env::getArray(self::ARRAY_KEY, ['fallback']));
    }

    public function testGetArrayParsesSingleValue(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, 'myapp.com');

        $this->assertSame(['myapp.com'], Env::getArray(self::ARRAY_KEY));
    }

    public function testGetArrayParsesCommaSeparatedValues(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, 'myapp.com,auth.myapp.com,support.myapp.com');

        $this->assertSame(['myapp.com', 'auth.myapp.com', 'support.myapp.com'], Env::getArray(self::ARRAY_KEY));
    }

    public function testGetArrayTrimsWhitespaceAroundValues(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, ' myapp.com , auth.myapp.com , support.myapp.com ');

        $this->assertSame(['myapp.com', 'auth.myapp.com', 'support.myapp.com'], Env::getArray(self::ARRAY_KEY));
    }

    public function testGetArrayFiltersEmptyStrings(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, 'myapp.com,,auth.myapp.com,  ,support.myapp.com');

        $this->assertSame(['myapp.com', 'auth.myapp.com', 'support.myapp.com'], Env::getArray(self::ARRAY_KEY));
    }

    public function testGetArrayPreservesStringZero(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, 'item1,0,item2');

        $this->assertSame(['item1', '0', 'item2'], Env::getArray(self::ARRAY_KEY));
    }

    public function testGetArrayReindexesAfterFiltering(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, 'first,,second');

        $result = Env::getArray(self::ARRAY_KEY);

        $this->assertSame([0, 1], array_keys($result));
        $this->assertSame(['first', 'second'], $result);
    }

    public function testGetArrayReturnsEmptyArrayWhenSetValueFiltersToEmpty(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, ' , , ');

        $this->assertSame([], Env::getArray(self::ARRAY_KEY, ['fallback']));
    }

    public function testGetArrayParsesQuotedCommaSeparatedValue(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, '"myapp.com,auth.myapp.com"');

        $this->assertSame(['myapp.com', 'auth.myapp.com'], Env::getArray(self::ARRAY_KEY));
    }

    public function testGetArrayThrowsWhenValueIsNotString(): void
    {
        $this->setEnvironmentValue(self::ARRAY_KEY, 'true');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Environment variable [TEST_ENV_ARRAY] cannot be read as an array.');

        Env::getArray(self::ARRAY_KEY);
    }

    public function testGlobalEnvArrayReturnsArray(): void
    {
        $this->assertSame(['fallback'], env_array(self::ARRAY_KEY, ['fallback']));

        $this->setEnvironmentValue(self::ARRAY_KEY, 'first,second');

        $this->assertSame(['first', 'second'], env_array(self::ARRAY_KEY));
    }

    public function testGetReturnsBooleanForTrueAndFalse()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        $this->assertTrue(Env::get('OLD_FLAG'));
    }

    public function testFlushRepositoryClearsRepository()
    {
        $repository1 = Env::getRepository();
        Env::flushRepository();
        $repository2 = Env::getRepository();

        // flushRepository creates a fresh instance — not the same object.
        $this->assertNotSame($repository1, $repository2);
    }

    public function testFlushRepositoryAllowsRewrite()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);
        $this->assertSame('1.0', Env::get('TEST_VERSION'));

        // Manually clear the env var and flush repository.
        Env::deleteMany(['TEST_VERSION']);
        Env::flushRepository();

        // Now a fresh load can write TEST_VERSION again.
        putenv('TEST_VERSION=overridden');
        $this->assertSame('overridden', Env::get('TEST_VERSION'));

        // Cleanup.
        putenv('TEST_VERSION');
    }

    public function testDeleteManyClearsFromAllAdapters()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        // Values are present in all adapters.
        $this->assertSame('1.0', Env::get('TEST_VERSION'));
        $this->assertArrayHasKey('TEST_VERSION', $_SERVER);
        $this->assertArrayHasKey('TEST_VERSION', $_ENV);
        $this->assertNotFalse(getenv('TEST_VERSION'));

        Env::deleteMany(['TEST_VERSION']);

        // Deleted from all three.
        $this->assertArrayNotHasKey('TEST_VERSION', $_SERVER);
        $this->assertArrayNotHasKey('TEST_VERSION', $_ENV);
        $this->assertFalse(getenv('TEST_VERSION'));
    }

    public function testDeleteManyAllowsRewriteAfterRepositoryReset()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);
        $this->assertSame('1.0', Env::get('TEST_VERSION'));

        Env::deleteMany(['TEST_VERSION', 'OLD_FLAG']);
        Env::flushRepository();

        // After delete + flush, the fresh ImmutableWriter allows writing.
        DotenvManager::flushState();
        DotenvManager::load([__DIR__ . '/envs/newEnv']);

        $this->assertSame('2.0', Env::get('TEST_VERSION'));
        $this->assertNull(Env::get('OLD_FLAG'));
    }
}
