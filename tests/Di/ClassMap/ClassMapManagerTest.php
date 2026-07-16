<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\ClassMap;

use Composer\Autoload\ClassLoader;
use Countable;
use Hypervel\Di\ClassMap\ClassMapManager;
use Hypervel\Support\Composer;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ClassMapManagerTest extends TestCase
{
    private ClassLoader $originalLoader;

    private ClassLoader $isolatedLoader;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalLoader = Composer::getLoader();
        $this->isolatedLoader = new ClassLoader;
        $this->isolatedLoader->register();
        Composer::setLoader($this->isolatedLoader);
    }

    protected function tearDown(): void
    {
        $this->isolatedLoader->unregister();
        Composer::setLoader($this->originalLoader);

        parent::tearDown();
    }

    public function testHasEntriesReturnsFalseWhenEmpty(): void
    {
        $this->assertFalse(ClassMapManager::hasEntries());
    }

    public function testAddRegistersEntriesAndAppliesToAutoloader(): void
    {
        $fakePath = '/tmp/fake_replacement.php';

        // Use a class name that definitely doesn't exist
        ClassMapManager::add([
            'Hypervel\Tests\Di\ClassMap\NonExistentClassForTesting' => $fakePath,
        ]);

        $this->assertTrue(ClassMapManager::hasEntries());
        $this->assertSame(
            ['Hypervel\Tests\Di\ClassMap\NonExistentClassForTesting' => $fakePath],
            ClassMapManager::getEntries()
        );

        // Verify it was added to Composer's class map
        $composerMap = Composer::getLoader()->getClassMap();
        $this->assertSame($fakePath, $composerMap['Hypervel\Tests\Di\ClassMap\NonExistentClassForTesting']);
    }

    public function testAddThrowsWhenClassAlreadyLoaded(): void
    {
        // This test class itself is already loaded
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot override class map for [' . self::class . ']');

        ClassMapManager::add([
            self::class => '/tmp/replacement.php',
        ]);
    }

    public function testAddThrowsWhenInterfaceAlreadyLoaded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot override class map');

        ClassMapManager::add([
            Countable::class => '/tmp/replacement.php',
        ]);
    }

    public function testAddThrowsWhenTraitAlreadyLoaded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot override class map');

        ClassMapManager::add([
            LoadedTraitForClassMapTest::class => '/tmp/replacement.php',
        ]);
    }

    public function testAddMergesMultipleCalls(): void
    {
        ClassMapManager::add([
            'Fake\ClassA' => '/tmp/a.php',
        ]);
        ClassMapManager::add([
            'Fake\ClassB' => '/tmp/b.php',
        ]);

        $this->assertSame([
            'Fake\ClassA' => '/tmp/a.php',
            'Fake\ClassB' => '/tmp/b.php',
        ], ClassMapManager::getEntries());
    }

    public function testFlushStateRemovesAllEntries(): void
    {
        ClassMapManager::add([
            'Fake\ClassA' => '/tmp/a.php',
        ]);

        ClassMapManager::flushState();

        $this->assertFalse(ClassMapManager::hasEntries());
        $this->assertSame([], ClassMapManager::getEntries());
    }
}

trait LoadedTraitForClassMapTest
{
}
