<?php

declare(strict_types=1);

namespace Hypervel\Tests\Container;

use Hypervel\Container\ReflectionManager;
use Hypervel\Tests\TestCase;
use ReflectionException;

/**
 * @internal
 * @coversNothing
 */
class ReflectionManagerTest extends TestCase
{
    protected function tearDown(): void
    {
        ReflectionManager::clear();

        parent::tearDown();
    }

    public function testReflectClassReturnsSameInstanceOnRepeatedCalls(): void
    {
        $first = ReflectionManager::reflectClass(ReflectionManagerTestStub::class);
        $second = ReflectionManager::reflectClass(ReflectionManagerTestStub::class);

        $this->assertSame($first, $second);
    }

    public function testReflectMethodReturnsSameInstanceOnRepeatedCalls(): void
    {
        $first = ReflectionManager::reflectMethod(ReflectionManagerTestStub::class, 'greet');
        $second = ReflectionManager::reflectMethod(ReflectionManagerTestStub::class, 'greet');

        $this->assertSame($first, $second);
    }

    public function testReflectPropertyReturnsSameInstanceOnRepeatedCalls(): void
    {
        $first = ReflectionManager::reflectProperty(ReflectionManagerTestStub::class, 'name');
        $second = ReflectionManager::reflectProperty(ReflectionManagerTestStub::class, 'name');

        $this->assertSame($first, $second);
    }

    public function testClearResetsClassCache(): void
    {
        $before = ReflectionManager::reflectClass(ReflectionManagerTestStub::class);

        ReflectionManager::clear();

        $after = ReflectionManager::reflectClass(ReflectionManagerTestStub::class);

        $this->assertNotSame($before, $after);
    }

    public function testClearResetsMethodCache(): void
    {
        $before = ReflectionManager::reflectMethod(ReflectionManagerTestStub::class, 'greet');

        ReflectionManager::clear();

        $after = ReflectionManager::reflectMethod(ReflectionManagerTestStub::class, 'greet');

        $this->assertNotSame($before, $after);
    }

    public function testClearResetsPropertyCache(): void
    {
        $before = ReflectionManager::reflectProperty(ReflectionManagerTestStub::class, 'name');

        ReflectionManager::clear();

        $after = ReflectionManager::reflectProperty(ReflectionManagerTestStub::class, 'name');

        $this->assertNotSame($before, $after);
    }

    public function testNonExistentClassThrowsReflectionException(): void
    {
        $this->expectException(ReflectionException::class);

        ReflectionManager::reflectClass('NonExistentClass');
    }

    public function testNonExistentMethodThrowsReflectionException(): void
    {
        $this->expectException(ReflectionException::class);

        ReflectionManager::reflectMethod(ReflectionManagerTestStub::class, 'nonExistentMethod');
    }

    public function testNonExistentPropertyThrowsReflectionException(): void
    {
        $this->expectException(ReflectionException::class);

        ReflectionManager::reflectProperty(ReflectionManagerTestStub::class, 'nonExistentProperty');
    }

    public function testReflectMethodUsesCachedClassReflection(): void
    {
        $classReflection = ReflectionManager::reflectClass(ReflectionManagerTestStub::class);
        $methodReflection = ReflectionManager::reflectMethod(ReflectionManagerTestStub::class, 'greet');

        // The method's declaring class should be the same cached reflection
        $this->assertSame($classReflection->getName(), $methodReflection->getDeclaringClass()->getName());
    }
}

class ReflectionManagerTestStub
{
    public string $name = 'test';

    public function greet(): string
    {
        return 'hello';
    }
}
