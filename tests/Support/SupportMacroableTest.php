<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use BadMethodCallException;
use Closure;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Tests\TestCase;

class SupportMacroableTest extends TestCase
{
    private EmptyMacroable $macroable;

    protected function setUp(): void
    {
        parent::setUp();

        $this->macroable = $this->createObjectForTrait();
    }

    protected function tearDown(): void
    {
        EmptyMacroable::flushMacros();
        TestMacroable::flushMacros();

        parent::tearDown();
    }

    private function createObjectForTrait(): EmptyMacroable
    {
        return new EmptyMacroable;
    }

    public function testRegisterMacro(): void
    {
        $macroable = $this->macroable;
        $macroable::macro(__CLASS__, function () {
            return 'Taylor';
        });
        $this->assertSame('Taylor', $macroable::{__CLASS__}());
    }

    public function testHasMacro(): void
    {
        $macroable = $this->macroable;
        $macroable::macro('foo', function () {
            return 'Taylor';
        });
        $this->assertTrue($macroable::hasMacro('foo'));
        $this->assertFalse($macroable::hasMacro('bar'));
    }

    public function testRegisterMacroAndCallWithoutStatic(): void
    {
        $macroable = $this->macroable;
        $macroable::macro(__CLASS__, function () {
            return 'Taylor';
        });
        $this->assertSame('Taylor', $macroable->{__CLASS__}());
    }

    public function testWhenCallingMacroClosureIsBoundToObject(): void
    {
        TestMacroable::macro('tryInstance', function () {
            return $this->protectedVariable;
        });
        TestMacroable::macro('tryStatic', function () {
            return static::getProtectedStatic();
        });
        $instance = new TestMacroable;

        $result = $instance->tryInstance();
        $this->assertSame('instance', $result);

        $result = TestMacroable::tryStatic();
        $this->assertSame('static', $result);
    }

    public function testClassBasedMacros(): void
    {
        TestMacroable::mixin(new TestMixin);
        $instance = new TestMacroable;
        $this->assertSame('instance-Adam', $instance->methodOne('Adam'));
    }

    public function testClassBasedMacrosNoReplace(): void
    {
        TestMacroable::macro('methodThree', function () {
            return 'bar';
        });
        TestMacroable::mixin(new TestMixin, false);
        $instance = new TestMacroable;
        $this->assertSame('bar', $instance->methodThree());

        TestMacroable::mixin(new TestMixin);
        $this->assertSame('foo', $instance->methodThree());
    }

    public function testFlushMacros(): void
    {
        TestMacroable::macro('flushMethod', function () {
            return 'flushMethod';
        });

        $instance = new TestMacroable;

        $this->assertSame('flushMethod', $instance->flushMethod());

        TestMacroable::flushMacros();

        $this->expectException(BadMethodCallException::class);

        $instance->flushMethod();
    }

    public function testFlushMacrosStatic(): void
    {
        TestMacroable::macro('flushMethod', function () {
            return 'flushMethod';
        });

        $instance = new TestMacroable;

        $this->assertSame('flushMethod', $instance::flushMethod());

        TestMacroable::flushMacros();

        $this->expectException(BadMethodCallException::class);

        $instance::flushMethod();
    }

    public function testMacroWithArguments(): void
    {
        $this->macroable::macro('concatenate', function ($arg1, $arg2) {
            return $arg1 . ' ' . $arg2;
        });

        $result = $this->macroable::concatenate('Hello', 'World');
        $this->assertSame('Hello World', $result);
    }

    public function testMacroWithDefaultArguments(): void
    {
        $this->macroable::macro('greet', function ($name = 'Guest') {
            return 'Hello, ' . $name;
        });

        $this->assertSame('Hello, Guest', $this->macroable::greet());
        $this->assertSame('Hello, Saleh', $this->macroable::greet('Saleh'));
    }

    public function testCallingUndefinedMacroThrowsException(): void
    {
        $this->expectException(BadMethodCallException::class);

        $this->macroable::nonExistentMacro();
    }

    public function testMethodConflictDoesNotThrowException(): void
    {
        $this->macroable::macro('existingMethod', function () {
            return 'oldMethod';
        });

        // Replacing existing macro.
        $this->macroable::macro('existingMethod', function () {
            return 'newMethod';
        });

        $this->assertSame('newMethod', $this->macroable::existingMethod());
    }

    public function testStaticCallOfNonStaticClosure(): void
    {
        $this->macroable::macro('nonStaticClosure', function () {
            return 'Taylor';
        });

        $this->assertSame('Taylor', $this->macroable::nonStaticClosure());
    }

    public function testNonStaticCallOfNonStaticClosure(): void
    {
        $this->macroable::macro('nonStaticClosure', function () {
            return 'Taylor';
        });

        $this->assertSame('Taylor', $this->macroable->nonStaticClosure());
    }

    public function testStaticCallOfStaticClosure(): void
    {
        $this->macroable::macro('staticClosure', static function () {
            return 'Taylor';
        });

        $this->assertSame('Taylor', $this->macroable::staticClosure());
    }

    public function testNonStaticCallOfStaticClosure(): void
    {
        $this->macroable::macro('staticClosure', static function () {
            return 'Taylor';
        });

        $this->assertSame('Taylor', $this->macroable->staticClosure());
    }

    public function testNonStaticCallOfStaticClosureBindsClassScope(): void
    {
        TestMacroable::macro('staticClosure', static function () {
            return static::getProtectedStatic();
        });

        $this->assertSame('static', (new TestMacroable)->staticClosure());
    }

    public function testClosureFromInternalFunctionRetainsItsCallableBinding(): void
    {
        $this->macroable::macro('length', Closure::fromCallable('strlen'));

        $this->assertSame(6, $this->macroable::length('Taylor'));
        $this->assertSame(6, $this->macroable->length('Taylor'));
    }

    public function testBoundInstanceMethodFirstClassCallableRetainsItsBinding(): void
    {
        $callable = new TestMacroCallable;
        $this->macroable::macro('instanceCallable', $callable->instanceMethod(...));

        $this->assertSame('instance-Taylor', $this->macroable::instanceCallable('Taylor'));
        $this->assertSame('instance-Taylor', $this->macroable->instanceCallable('Taylor'));
    }

    public function testStaticMethodFirstClassCallableRetainsItsBinding(): void
    {
        $this->macroable::macro('staticCallable', TestMacroCallable::staticMethod(...));

        $this->assertSame('static-Taylor', $this->macroable::staticCallable('Taylor'));
        $this->assertSame('static-Taylor', $this->macroable->staticCallable('Taylor'));
    }

    public function testInvokableObjectCanBeCalledStaticallyAndNonStatically(): void
    {
        $this->macroable::macro('invokable', new TestInvokableMacro);

        $this->assertSame('invokable-Taylor', $this->macroable::invokable('Taylor'));
        $this->assertSame('invokable-Taylor', $this->macroable->invokable('Taylor'));
    }
}

class EmptyMacroable
{
    use Macroable;
}

class TestMacroable
{
    use Macroable;

    protected string $protectedVariable = 'instance';

    protected static function getProtectedStatic(): string
    {
        return 'static';
    }
}

class TestMixin
{
    public function methodOne()
    {
        return function ($value) {
            return $this->methodTwo($value);
        };
    }

    protected function methodTwo()
    {
        return function ($value) {
            return $this->protectedVariable . '-' . $value;
        };
    }

    protected function methodThree()
    {
        return function () {
            return 'foo';
        };
    }
}

class TestMacroCallable
{
    public function instanceMethod(string $value): string
    {
        return 'instance-' . $value;
    }

    public static function staticMethod(string $value): string
    {
        return 'static-' . $value;
    }
}

class TestInvokableMacro
{
    public function __invoke(string $value): string
    {
        return 'invokable-' . $value;
    }
}
