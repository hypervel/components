<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Error;
use Hypervel\Tests\TestCase;
use RuntimeException;

class ReflectionHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ReflectionHelperLazyClass::$constructorCalled = false;
        ReflectionHelperLazyClassWithArrayParameter::$constructorCalled = false;
    }

    protected function tearDown(): void
    {
        ReflectionHelperLazyClass::$constructorCalled = false;
        ReflectionHelperLazyClassWithArrayParameter::$constructorCalled = false;

        parent::tearDown();
    }

    public function testLazy(): void
    {
        $instance = lazy(ReflectionHelperLazyClass::class, function (ReflectionHelperLazyClass $instance): void {
            $instance->__construct('foo', 'bar');
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testLazyCanAcceptShortClosure(): void
    {
        $instance = lazy(
            ReflectionHelperLazyClass::class,
            fn (ReflectionHelperLazyClass $instance) => $instance->__construct('foo', 'bar')
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testLazyThrowsExceptionWhenConstructorIsNotCalled(): void
    {
        $instance = lazy(ReflectionHelperLazyClass::class, function (ReflectionHelperLazyClass $instance): void {
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);

        $this->expectException(Error::class);
        $this->expectExceptionMessage(
            'Typed property Hypervel\Tests\Support\ReflectionHelperLazyClass::$first must not be accessed before initialization'
        );

        $instance->first;
    }

    public function testLazyCanAcceptHashForProperties(): void
    {
        $instance = lazy(ReflectionHelperLazyClass::class, fn (ReflectionHelperLazyClass $instance) => [
            'second' => 'bar',
            'first' => 'foo',
        ]);

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testLazyCanAcceptListForProperties(): void
    {
        $instance = lazy(
            ReflectionHelperLazyClass::class,
            fn (ReflectionHelperLazyClass $instance) => ['foo', 'bar']
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testLazyCanAcceptSingleValueForConstructor(): void
    {
        $instance = lazy(
            ReflectionHelperLazyClassWithArrayParameter::class,
            fn (ReflectionHelperLazyClassWithArrayParameter $instance) => [['foo']]
        );

        $this->assertFalse(ReflectionHelperLazyClassWithArrayParameter::$constructorCalled);
        $this->assertSame(['foo'], $instance->first);
        $this->assertTrue(ReflectionHelperLazyClassWithArrayParameter::$constructorCalled);
    }

    public function testLazySupportsPositionAndNamedArguments(): void
    {
        $instance = lazy(
            ReflectionHelperLazyClass::class,
            fn (ReflectionHelperLazyClass $instance) => ['foo', 'second' => 'bar']
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testLazyThrowsWhenPositionalArgumentsComeAfterNamedArguments(): void
    {
        $instance = lazy(
            ReflectionHelperLazyClass::class,
            fn (ReflectionHelperLazyClass $instance) => ['second' => 'bar', 'foo']
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot use positional argument after named argument during unpacking');

        $instance->first;
    }

    public function testLazyCanReturnInitializedObject(): void
    {
        $instance = lazy(ReflectionHelperLazyClass::class, function (ReflectionHelperLazyClass $instance) {
            $instance->__construct('foo');

            return $instance;
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertNull($instance->second);
    }

    public function testLazyMustInitializeObject(): void
    {
        $instance = lazy(
            ReflectionHelperLazyClass::class,
            fn (ReflectionHelperLazyClass $instance) => new ReflectionHelperLazyClass('foo')
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessage(
            'Typed property Hypervel\Tests\Support\ReflectionHelperLazyClass::$first must not be accessed before initialization'
        );

        $instance->first;
    }

    public function testLazyCanEagerlySetProperties(): void
    {
        $instance = lazy(
            ReflectionHelperLazyClass::class,
            fn () => ['foo', 'bar'],
            eager: ['eager' => 'baz']
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);
        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);
    }

    public function testClosureOnlyLazy(): void
    {
        $instance = lazy(function (ReflectionHelperLazyClass $instance): void {
            $instance->__construct('foo', 'bar');
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testClosureOnlyLazyCanAcceptShortClosure(): void
    {
        $instance = lazy(fn (ReflectionHelperLazyClass $instance) => $instance->__construct('foo', 'bar'));

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testClosureOnlyLazyThrowsExceptionWhenConstructorIsNotCalled(): void
    {
        $instance = lazy(function (ReflectionHelperLazyClass $instance): void {
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);

        $this->expectException(Error::class);
        $this->expectExceptionMessage(
            'Typed property Hypervel\Tests\Support\ReflectionHelperLazyClass::$first must not be accessed before initialization'
        );

        $instance->first;
    }

    public function testClosureOnlyLazyThrowsWhenNoClassIsSpecifiedInClosure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The first parameter of the given Closure is missing a type hint.');

        lazy(function ($instance): void {
        });
    }

    public function testClosureOnlyLazyCanAcceptHashForProperties(): void
    {
        $instance = lazy(fn (ReflectionHelperLazyClass $instance) => [
            'second' => 'bar',
            'first' => 'foo',
        ]);

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testClosureOnlyLazyCanAcceptListForProperties(): void
    {
        $instance = lazy(fn (ReflectionHelperLazyClass $instance) => ['foo', 'bar']);

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testClosureOnlyLazyCanAcceptSingleValueForConstructor(): void
    {
        $instance = lazy(fn (ReflectionHelperLazyClassWithArrayParameter $instance) => [['foo']]);

        $this->assertFalse(ReflectionHelperLazyClassWithArrayParameter::$constructorCalled);
        $this->assertSame(['foo'], $instance->first);
        $this->assertTrue(ReflectionHelperLazyClassWithArrayParameter::$constructorCalled);
    }

    public function testClosureOnlyLazySupportsPositionAndNamedArguments(): void
    {
        $instance = lazy(fn (ReflectionHelperLazyClass $instance) => ['foo', 'second' => 'bar']);

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testClosureOnlyLazyThrowsWhenPositionalArgumentsComeAfterNamedArguments(): void
    {
        $instance = lazy(fn (ReflectionHelperLazyClass $instance) => ['second' => 'bar', 'foo']);

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Cannot use positional argument after named argument during unpacking');

        $instance->first;
    }

    public function testClosureOnlyLazyCanReturnInitializedObject(): void
    {
        $instance = lazy(function (ReflectionHelperLazyClass $instance) {
            $instance->__construct('foo');

            return $instance;
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertNull($instance->second);
    }

    public function testClosureOnlyLazyMustInitializeObject(): void
    {
        $instance = lazy(
            fn (ReflectionHelperLazyClass $instance) => new ReflectionHelperLazyClass('foo')
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessage(
            'Typed property Hypervel\Tests\Support\ReflectionHelperLazyClass::$first must not be accessed before initialization'
        );

        $instance->first;
    }

    public function testProxy(): void
    {
        $factory = fn () => new ReflectionHelperLazyClass('foo', 'bar');
        $instance = proxy(
            ReflectionHelperLazyClass::class,
            fn (ReflectionHelperLazyClass $proxy) => $factory()
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testProxyCanEagerlySetProperties(): void
    {
        $factory = fn () => new ReflectionHelperLazyClass('foo', 'bar');
        $instance = proxy(
            ReflectionHelperLazyClass::class,
            fn () => $factory(),
            eager: ['eager' => 'baz']
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);
        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertFalse(isset($instance->eager));
    }

    public function testProxyCanCopyEagerPropertiesToActualObject(): void
    {
        $factory = fn () => new ReflectionHelperLazyClass('foo', 'bar');
        $instance = proxy(ReflectionHelperLazyClass::class, function ($proxy, array $eager) use ($factory) {
            $instance = $factory();

            foreach ($eager as $property => $value) {
                $instance->{$property} = $value;
            }

            return $instance;
        }, eager: ['eager' => 'baz']);

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);
        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('baz', $instance->eager);
    }

    public function testProxyCanAcceptShortClosure(): void
    {
        $factory = fn () => new ReflectionHelperLazyClass('foo', 'bar');
        $instance = proxy(
            ReflectionHelperLazyClass::class,
            fn (ReflectionHelperLazyClass $proxy) => $factory()
        );

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testProxyThrowsExceptionWhenObjectIsNotReturned(): void
    {
        $instance = proxy(ReflectionHelperLazyClass::class, function (ReflectionHelperLazyClass $proxy): void {
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);

        $this->expectException(Error::class);
        $this->expectExceptionMessage(
            'Lazy proxy factory must return an instance of a class compatible with '
            . 'Hypervel\Tests\Support\ReflectionHelperLazyClass, null returned'
        );

        $instance->first;
    }

    public function testProxyMustNotInitializeProxy(): void
    {
        $instance = proxy(ReflectionHelperLazyClass::class, function (ReflectionHelperLazyClass $proxy) {
            $proxy->__construct('foo');

            return $proxy;
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Lazy proxy factory must return a non-lazy object');

        $instance->first;
    }

    public function testClosureOnlyProxy(): void
    {
        $factory = fn () => new ReflectionHelperLazyClass('foo', 'bar');
        $instance = proxy(function (ReflectionHelperLazyClass $proxy) use ($factory) {
            return $factory();
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testClosureOnlyProxyCanAcceptShortClosure(): void
    {
        $factory = fn () => new ReflectionHelperLazyClass('foo', 'bar');
        $instance = proxy(fn (ReflectionHelperLazyClass $proxy) => $factory());

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testClosureOnlyProxyThrowsExceptionWhenObjectIsNotReturned(): void
    {
        $instance = proxy(function (ReflectionHelperLazyClass $proxy): void {
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);

        $this->expectException(Error::class);
        $this->expectExceptionMessage(
            'Lazy proxy factory must return an instance of a class compatible with '
            . 'Hypervel\Tests\Support\ReflectionHelperLazyClass, null returned'
        );

        $instance->first;
    }

    public function testClosureOnlyProxyThrowsWhenNoClassIsSpecifiedInClosure(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The first parameter of the given Closure is missing a type hint.');

        proxy(function ($proxy): void {
        });
    }

    public function testClosureOnlyProxyMustNotInitializeProxy(): void
    {
        $instance = proxy(function (ReflectionHelperLazyClass $proxy) {
            $proxy->__construct('foo');

            return $proxy;
        });

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->expectException(Error::class);
        $this->expectExceptionMessage('Lazy proxy factory must return a non-lazy object');

        $instance->first;
    }

    public function testProxyCanUseClosureReturnTypeForClassDetection(): void
    {
        $factory = fn () => new ReflectionHelperLazyClass('foo', 'bar');
        $instance = proxy(fn (): ReflectionHelperLazyClass => $factory());

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }

    public function testProxyFallsBackToParameterTypeForRelativeReturnType(): void
    {
        $instance = proxy(ReflectionHelperParentReturnFactory::make(...));

        $this->assertFalse(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('foo', $instance->first);
        $this->assertTrue(ReflectionHelperLazyClass::$constructorCalled);
        $this->assertSame('bar', $instance->second);
    }
}

class ReflectionHelperLazyClass
{
    public static bool $constructorCalled = false;

    public string $eager;

    public function __construct(
        public string $first,
        public ?string $second = null,
    ) {
        self::$constructorCalled = true;
    }
}

class ReflectionHelperLazyClassWithArrayParameter
{
    public static bool $constructorCalled = false;

    public function __construct(
        public array $first,
    ) {
        self::$constructorCalled = true;
    }
}

class ReflectionHelperParentReturnFactory extends ReflectionHelperLazyClass
{
    public static function make(ReflectionHelperLazyClass $proxy): parent
    {
        return new parent('foo', 'bar');
    }
}
