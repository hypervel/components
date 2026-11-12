<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\UselessConstructs\Data;

class UselessWithFunctionCall
{
    public function foo(): string
    {
        return 'foo';
    }

    public function bar(): string
    {
        return 'bar';
    }

    public function invalidCallback(): string
    {
        return with('value', false);
    }

    public function created(): UselessWithFunctionCall
    {
        return new UselessWithFunctionCall();
    }
}
