<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\UselessConstructs\Data;

use ArrayObject;

class UselessValueFunctionCall
{
    public function foo(): string
    {
        return 'foo';
    }

    public function upperCase(): string
    {
        return 'foo';
    }

    public function named(): string
    {
        return 'foo';
    }

    public function constantArgument(): string
    {
        return 'foo';
    }

    public function evaluatedArgument(): string
    {
        return value('foo', $this->count());
    }

    public function interpolatedArgument(): string
    {
        return value('foo', "{$this->count()}");
    }

    public function undefinedConstantArgument(): string
    {
        return value('foo', UNDEFINED_CONSTANT);
    }

    public function created(): ArrayObject
    {
        return new ArrayObject();
    }

    public function namedArguments(): string
    {
        return 'bar';
    }

    private function count(): int
    {
        return 1;
    }
}
