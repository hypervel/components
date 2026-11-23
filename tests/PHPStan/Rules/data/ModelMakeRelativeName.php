<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

// Hypervel: final with a fixed connection, since static and self calls can construct a subclass.

final class ModelMakeRelativeName extends ModelMakeTarget
{
    public static function makeStatic(): static
    {
        return static::make();
    }

    public static function makeSelf(): self
    {
        return self::make();
    }
}

class NotAModelMakeRelativeName
{
    public static function make(): self
    {
        return new self();
    }

    public static function makeStatic(): self
    {
        return static::make();
    }
}
