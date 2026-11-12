<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\UselessConstructs\Data;

class UselessWithFunctionCall
{
    public function foo(): string
    {
        return with('foo');
    }

    public function bar(): string
    {
        return with('bar', null);
    }

    public function invalidCallback(): string
    {
        return with('value', false);
    }

    public function created(): UselessWithFunctionCall
    {
        return with(new UselessWithFunctionCall());
    }
}
