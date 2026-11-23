<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data\NamespacedValueFunction;

function value(string $value): string
{
    return $value;
}

class NamespacedValueFunctionCall
{
    public function foo(): string
    {
        return value('foo');
    }
}
