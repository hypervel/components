<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\UselessConstructs\Data;

class CorrectValueFunctionCall
{
    public function foo(): string
    {
        return value(static function (int $foo) {
            return 'foo';
        });
    }

    /** @param array{0: string} $arguments */
    public function unpacked(array $arguments): string
    {
        return value(...$arguments);
    }

    public function reorderedNamed(): string
    {
        return value(foo: 1, value: static fn (int $foo) => 'foo');
    }
}
