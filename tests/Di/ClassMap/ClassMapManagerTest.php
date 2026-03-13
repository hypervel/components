<?php

declare(strict_types=1);

namespace Hypervel\Tests\Di\ClassMap;

use Countable;
use Hypervel\Di\ClassMap\ClassMapManager;
use Hypervel\Support\Composer;
use Hypervel\Tests\TestCase;
use RuntimeException;

/**
 * @internal
 * @coversNothing
 */
class ClassMapManagerTest extends TestCase
{
    public function testHasEntriesReturnsFalseWhenEmpty()
    {
        $this->assertFalse(ClassMapManager::hasEntries());
    }

    public function testAddRegistersEntriesAndAppliestoAutoloader()
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

    public function testAddThrowsWhenClassAlreadyLoaded()
    {
        // This test class itself is already loaded
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot override class map for [' . self::class . ']');

        ClassMapManager::add([
            self::class => '/tmp/replacement.php',
        ]);
    }

    public function testAddThrowsWhenInterfaceAlreadyLoaded()
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot override class map');

        ClassMapManager::add([
            Countable::class => '/tmp/replacement.php',
        ]);
    }

    public function testAddThrowsWhenTraitAlreadyLoaded()
    {
        // Force-load the trait by referencing a class that uses it
        new \Hypervel\Tests\Di\Stub\ProxyTraitObject();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot override class map');

        ClassMapManager::add([
            \Hypervel\Di\Aop\ProxyTrait::class => '/tmp/replacement.php',
        ]);
    }

    public function testAddMergesMultipleCalls()
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

    public function testFlushStateRemovesAllEntries()
    {
        ClassMapManager::add([
            'Fake\ClassA' => '/tmp/a.php',
        ]);

        ClassMapManager::flushState();

        $this->assertFalse(ClassMapManager::hasEntries());
        $this->assertSame([], ClassMapManager::getEntries());
    }
}
