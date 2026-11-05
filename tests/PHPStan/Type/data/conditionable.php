<?php

namespace ConditionableStubs;

use App\User;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Support\Collection;
use Hypervel\Support\HigherOrderWhenProxy;
use Hypervel\Support\Traits\Conditionable;

use function PHPStan\Testing\assertType;

class Foo
{
    use Conditionable;
}

/** @param Builder<User> $query */
function test(Builder $query): void
{
    assertType('ConditionableStubs\Foo', (new Foo())->when(true, function (Foo $foo) {
        // do nothing
    }));

    assertType('ConditionableStubs\Foo', (new Foo())->when(true, function (Foo $foo) {
        return null;
    }));

    // Hypervel: a false condition returns the instance, so it stays in the type.
    assertType('ConditionableStubs\Foo|int<0, max>', (new Foo())->when(true, function (Foo $foo): int {
        return rand();
    }));

    // Test to make sure the callback has a non-null value.
    (new Foo())->when(User::first(), function (Foo $foo, $user): void {
        assertType(User::class, $user);
    });

    assertType('ConditionableStubs\Foo', (new Foo())->unless(true, function (Foo $foo) {
        // do nothing
    }));

    assertType('ConditionableStubs\Foo', (new Foo())->unless(true, function (Foo $foo) {
        return null;
    }));

    // Hypervel: a true condition returns the instance, so it stays in the type.
    assertType('ConditionableStubs\Foo|int<0, max>', (new Foo())->unless(true, function (Foo $foo): int {
        return rand();
    }));

    assertType('Hypervel\Database\Eloquent\Builder<App\User>', $query->when(true, static function (Builder $query): Builder {
        /** @phpstan-var Builder<User> $query */
        return $query->whereNull('name');
    }));
}

class Logger
{
    use Conditionable;

    public bool $toggle = false;

    public function has(string $key): bool
    {
        return true;
    }

    public function log(string $message): static
    {
        return $this;
    }

    private function flush(): void
    {
    }
}

/**
 * Hypervel: without a callback, when() and unless() return a proxy. Given a value, it takes the
 * condition from the value; otherwise it takes the condition from the next property or method.
 *
 * @param Collection<int, int> $numbers
 */
function higherOrder(Logger $logger, Collection $numbers, ?string $maybe, callable $callback): void
{
    assertType('Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, false>', $logger->when());
    assertType('Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, false>', $logger->unless());
    assertType('Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, true>', $logger->when($maybe));
    assertType('Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, true>', $logger->unless(true));

    assertType('Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, true>', $logger->when()->has('init'));
    assertType('ConditionableStubs\Logger', $logger->when()->has('init')->log('three'));
    assertType('ConditionableStubs\Logger', $logger->unless()->toggle->log('five'));
    assertType('bool|ConditionableStubs\Logger', $logger->when(true)->toggle);
    assertType('Hypervel\Support\Collection<int, int>', $numbers->when()->isNotEmpty()->map(fn ($number) => $number + 1));

    // The proxy runs outside the target's class, so it reaches only public members.
    assertType('*ERROR*', $logger->when(true)->flush());

    // A callback argument, even null, makes the call return the instance or the default's result.
    assertType('ConditionableStubs\Logger', $logger->when(false, null));
    assertType("'fallback'|ConditionableStubs\Logger", $logger->when(false, null, fn () => 'fallback'));
    assertType("'fallback'|ConditionableStubs\Logger", $logger->unless(true, null, fn () => 'fallback'));
    assertType("'default'|ConditionableStubs\Logger", $logger->when(default: fn () => 'default'));
    assertType('Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, true>', $logger->when(value: true));

    // What the callback or default returns is kept, even a proxy or mixed.
    $captured = $logger->when();
    assertType('ConditionableStubs\Logger|Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, false>', $logger->when(true, fn () => $captured));
    assertType('ConditionableStubs\Logger|Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, false>', $logger->when(false, null, fn () => $captured));
    assertType('mixed', $logger->when(true, $callback));

    // Setting the condition changes the proxy itself.
    $proxy = new HigherOrderWhenProxy($logger);
    $proxy->condition(true);
    assertType('Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, true>', $proxy);
}

class CustomWhen
{
    /** @return HigherOrderWhenProxy<Logger, true> */
    public function when(mixed $value = null): HigherOrderWhenProxy
    {
        return (new HigherOrderWhenProxy(new Logger))->condition(true);
    }
}

function customWhen(CustomWhen $custom): void
{
    // A method that is not Conditionable's keeps its own declaration.
    assertType('Hypervel\Support\HigherOrderWhenProxy<ConditionableStubs\Logger, true>', $custom->when());
}

class Formatter
{
    public function format(Logger $logger): void
    {
        // A callback's own $this stays the callback's class.
        assertType('$this(ConditionableStubs\Formatter)|ConditionableStubs\Logger', $logger->when(true, fn () => $this));
    }
}

/**
 * @template TKey of array-key
 * @template TValue
 *
 * @extends Collection<TKey, TValue>
 */
class Numbers extends Collection
{
    public static function strings(): void
    {
        // The proxy keeps the called object's types, and its members' static types mean that object.
        assertType('Hypervel\Support\HigherOrderWhenProxy<static(ConditionableStubs\Numbers<int, int>), true>', static::range(1, 3)->unless(false));
        assertType('ConditionableStubs\Numbers<int, int>|ConditionableStubs\Numbers<int, decimal-int-string>', static::range(1, 3)->unless(false)->map(fn ($number) => (string) $number));
    }
}
