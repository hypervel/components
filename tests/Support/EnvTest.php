<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;
use RuntimeException;

class EnvTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DotenvManager::flushState();
    }

    protected function tearDown(): void
    {
        DotenvManager::flushState();
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
