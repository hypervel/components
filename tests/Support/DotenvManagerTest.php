<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Dotenv\Repository\Adapter\AdapterInterface;
use Hypervel\Support\DotenvManager;
use Hypervel\Support\Env;
use Hypervel\Tests\TestCase;
use PhpOption\Option;
use PhpOption\Some;

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
        // Reset so we don't leak test env vars or custom adapters into
        // subsequent tests. flushState() clears custom adapters too.
        DotenvManager::reset();
        Env::flushState();

        parent::tearDown();
    }

    public function testLoad()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        $this->assertSame('1.0', env('TEST_VERSION'));
        $this->assertTrue(env('OLD_FLAG'));
    }

    public function testLoadIsIdempotent()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);
        DotenvManager::load([__DIR__ . '/envs/newEnv']);

        // Second load is ignored — still has old values.
        $this->assertSame('1.0', env('TEST_VERSION'));
        $this->assertTrue(env('OLD_FLAG'));
    }

    public function testReload()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);
        $this->assertSame('1.0', env('TEST_VERSION'));
        $this->assertTrue(env('OLD_FLAG'));

        DotenvManager::reload([__DIR__ . '/envs/newEnv']);
        $this->assertSame('2.0', env('TEST_VERSION'));
        $this->assertNull(env('OLD_FLAG'));
        $this->assertTrue(env('NEW_FLAG'));
    }

    public function testReloadDeletesRemovedKeys()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);
        $this->assertTrue(env('OLD_FLAG'));

        DotenvManager::reload([__DIR__ . '/envs/newEnv']);

        // OLD_FLAG exists in oldEnv but not in newEnv — must be deleted.
        $this->assertNull(env('OLD_FLAG'));
    }

    public function testReloadWithoutPriorLoadCallsLoad()
    {
        // reload() on a fresh state (no prior load) falls through to load().
        DotenvManager::reload([__DIR__ . '/envs/oldEnv']);

        $this->assertSame('1.0', env('TEST_VERSION'));
        $this->assertTrue(env('OLD_FLAG'));
    }

    public function testReloadUsesEnvRepository()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);
        DotenvManager::reload([__DIR__ . '/envs/newEnv']);

        // Values loaded via DotenvManager must be readable via Env::get(),
        // confirming both use the same repository.
        $this->assertSame('2.0', Env::get('TEST_VERSION'));
        $this->assertTrue(Env::get('NEW_FLAG'));
    }

    public function testResetClearsLoadedValues()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);
        $this->assertSame('1.0', env('TEST_VERSION'));

        DotenvManager::reset();

        // After reset, the env vars are removed.
        $this->assertNull(env('TEST_VERSION'));
    }

    public function testReloadClearsCustomAdapterValues()
    {
        // Register a custom adapter that writes to a shared store.
        $adapter = DotenvManagerTestAdapter::makeWithStore();
        Env::extend(fn () => $adapter);

        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        // Custom adapter received the values during load.
        $store = $adapter->getStore();
        $this->assertArrayHasKey('TEST_VERSION', $store);
        $this->assertSame('1.0', $store['TEST_VERSION']);

        DotenvManager::reload([__DIR__ . '/envs/newEnv']);

        // After reload, the old key was deleted from the custom adapter
        // and the new value was written.
        $store = $adapter->getStore();
        $this->assertSame('2.0', $store['TEST_VERSION']);
        $this->assertArrayNotHasKey('OLD_FLAG', $store);
    }

    public function testEnvDefaultValue()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);

        $this->assertSame('fallback', env('NONEXISTENT_KEY', 'fallback'));
        $this->assertNull(env('NONEXISTENT_KEY'));
    }

    public function testLoadWithNameParameter()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv'], '.env.testing');

        $this->assertSame('named_value', env('NAMED_KEY'));
    }

    public function testReloadWithNameParameter()
    {
        DotenvManager::load([__DIR__ . '/envs/oldEnv']);
        $this->assertSame('1.0', env('TEST_VERSION'));

        DotenvManager::reload([__DIR__ . '/envs/oldEnv'], '.env.testing');
        $this->assertSame('named_value', env('NAMED_KEY'));
        $this->assertNull(env('TEST_VERSION'));
    }

    public function testSafeLoadWithValidFile()
    {
        DotenvManager::safeLoad([__DIR__ . '/envs/oldEnv']);

        $this->assertSame('1.0', env('TEST_VERSION'));
        $this->assertTrue(env('OLD_FLAG'));
    }

    public function testSafeLoadWithMissingFileDoesNotThrow()
    {
        DotenvManager::safeLoad([__DIR__ . '/envs/nonexistent']);

        // No exception, and no values loaded.
        $this->assertNull(env('TEST_VERSION'));
    }

    public function testSafeLoadIsIdempotent()
    {
        DotenvManager::safeLoad([__DIR__ . '/envs/oldEnv']);
        DotenvManager::safeLoad([__DIR__ . '/envs/newEnv']);

        // Second call is ignored — still has old values.
        $this->assertSame('1.0', env('TEST_VERSION'));
        $this->assertTrue(env('OLD_FLAG'));
    }

    public function testSafeLoadWithNameParameter()
    {
        DotenvManager::safeLoad([__DIR__ . '/envs/oldEnv'], '.env.testing');

        $this->assertSame('named_value', env('NAMED_KEY'));
    }

    public function testSafeLoadPopulatesCachedValuesForReload()
    {
        DotenvManager::safeLoad([__DIR__ . '/envs/oldEnv']);
        $this->assertSame('1.0', env('TEST_VERSION'));
        $this->assertTrue(env('OLD_FLAG'));

        // Reload should clean up keys loaded by safeLoad.
        DotenvManager::reload([__DIR__ . '/envs/newEnv']);
        $this->assertSame('2.0', env('TEST_VERSION'));
        $this->assertNull(env('OLD_FLAG'));
        $this->assertTrue(env('NEW_FLAG'));
    }
}

/**
 * A test adapter that stores values in an internal array.
 *
 * @internal
 */
class DotenvManagerTestAdapter implements AdapterInterface
{
    /** @var array<string, string> */
    private array $store = [];

    public static function create()
    {
        return Some::create(new self());
    }

    public static function makeWithStore(): self
    {
        return new self();
    }

    /** @return array<string, string> */
    public function getStore(): array
    {
        return $this->store;
    }

    public function read(string $name)
    {
        return Option::fromArraysValue($this->store, $name);
    }

    public function write(string $name, string $value)
    {
        $this->store[$name] = $value;

        return true;
    }

    public function delete(string $name)
    {
        unset($this->store[$name]);

        return true;
    }
}
