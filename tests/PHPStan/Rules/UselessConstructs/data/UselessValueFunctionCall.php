<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\UselessConstructs\Data;

use ArrayObject;

class UselessValueFunctionCall
{
    public function foo(): string
    {
        return value('foo');
    }

    public function upperCase(): string
    {
        return VALUE('foo');
    }

    public function named(): string
    {
        return value(value: 'foo');
    }

    public function constantArgument(): string
    {
        return value('foo', null);
    }

    public function evaluatedArgument(): string
    {
        return value('foo', $this->count());
    }

    public function undefinedConstantArgument(): string
    {
        return value('foo', UNDEFINED_CONSTANT);
    }

    public function created(): ArrayObject
    {
        return value(new ArrayObject());
    }

    private function count(): int
    {
        return 1;
    }
}
