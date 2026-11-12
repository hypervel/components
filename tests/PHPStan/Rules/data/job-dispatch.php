<?php

namespace Hypervel\Tests\PHPStan\Rules\Data;

HypervelJob::dispatch();
HypervelJob::dispatchSync();
HypervelJob::dispatchAfterResponse();

HypervelJob::dispatch('foo');
HypervelJob::dispatch(1, 'foo');
HypervelJob::dispatch(true, false);

HypervelJob::dispatchSync('foo');
HypervelJob::dispatchSync(1, 'foo');
HypervelJob::dispatchSync(true, false);

HypervelJob::dispatchAfterResponse('foo');
HypervelJob::dispatchAfterResponse(1, 'foo');
HypervelJob::dispatchAfterResponse(true, false);

HypervelJob::dispatchIf(true, 1, 'foo');
HypervelJob::dispatchUnless(true, 1, 1);

HypervelJobWithoutConstructor::dispatch();
HypervelJobWithoutConstructor::dispatch('foo');
HypervelJobWithoutConstructor::dispatchSync();
HypervelJobWithoutConstructor::dispatchSync('foo');
HypervelJobWithoutConstructor::dispatchAfterResponse();
HypervelJobWithoutConstructor::dispatchAfterResponse('foo');

HypervelJobWithoutConstructor::dispatchIf(true);
HypervelJobWithoutConstructor::dispatchUnless(true, 'foo');

// Hypervel: the constructor also runs before a closure condition, and a named or unpacked argument
// is bound as PHP binds it. A class's own dispatch method keeps its signature.
HypervelJob::dispatchIf(static fn (HypervelJob $job): bool => true, 1, 'foo');
HypervelJob::dispatchUnless(foo: 'foo', boolean: false);
HypervelJob::dispatchIf(...[true, 'foo', 1]);
HypervelJobWithOwnDispatch::dispatch(1);

// Hypervel: the method name matches in any case.
HypervelJob::DISPATCH(1, 'foo');

// Hypervel: a call through self, static or parent constructs the calling class. A non-final class's
// subclass can declare another constructor, so it is not checked. A final class is checked against its
// own constructor, also when it calls the trait's method through parent from its own dispatch().
class HypervelParentJob
{
    use \Hypervel\Foundation\Bus\Dispatchable;

    public function __construct(public string $foo)
    {
    }

    public static function dispatchDefault(): \Hypervel\Foundation\Bus\PendingDispatch
    {
        return static::dispatch(1);
    }
}

final class HypervelChildJob extends HypervelParentJob
{
    public function __construct(public int $bar)
    {
        parent::__construct((string) $bar);
    }

    public static function dispatch(mixed ...$arguments): \Hypervel\Foundation\Bus\PendingDispatch
    {
        return parent::dispatch('foo');
    }
}
