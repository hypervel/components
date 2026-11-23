<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\Builder;

enum FooEnum: string
{
    case FOO = 'foo';
}

Builder::macro('macroWithEnumDefaultValue', static function (string $arg = 'foobar', $b = FooEnum::FOO): string {
    return $arg;
});
