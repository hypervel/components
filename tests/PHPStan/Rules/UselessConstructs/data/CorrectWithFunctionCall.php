<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\UselessConstructs\Data;

class CorrectWithFunctionCall
{
    public function foo(): string
    {
        return with('foo', static function (string $bar) {
            return strtoupper($bar);
        });
    }

    public function bar(): string
    {
        return with('foo', 'strtoupper');
    }

    /** @param array{0: string, 1: callable(string): string} $arguments */
    public function unpacked(array $arguments): string
    {
        return with(...$arguments);
    }

    public function reorderedNamed(): string
    {
        return with(callback: static fn (string $bar) => strtoupper($bar), value: 'foo');
    }
}
