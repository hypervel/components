<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\DotenvManager;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DotenvManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // DotenvManager::load() is a one-shot method that returns early when
        // cachedValues is already set. In the full suite, ClassLoader::init()
        // calls load() during Testbench bootstrap, so our test's load() call
        // would be a no-op. Reset clears all static state so load() works fresh.
        DotenvManager::reset();
    }

    protected function tearDown(): void
    {
        // Reset so we don't leak test env vars into subsequent tests.
        // The next code that calls DotenvManager::load() will re-populate.
        DotenvManager::reset();

        parent::tearDown();
    }

    public function testLoad()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        $this->assertSame('1.0', env('TEST_VERSION'));
        $this->assertTrue(env('OLD_FLAG'));
    }

    public function testReload()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);
        $this->assertSame('1.0', env('TEST_VERSION'));
        $this->assertTrue(env('OLD_FLAG'));

        DotenvManager::reload([__DIR__ . '/envs/newEnv'], true);
        $this->assertSame('2.0', env('TEST_VERSION'));
        $this->assertNull(env('OLD_FLAG'));
        $this->assertTrue(env('NEW_FLAG'));
    }

    public function testEnvDefaultValue()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        $this->assertSame('fallback', env('NONEXISTENT_KEY', 'fallback'));
        $this->assertNull(env('NONEXISTENT_KEY'));
    }
}
