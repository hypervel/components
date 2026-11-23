<?php

declare(strict_types=1);

namespace WorkerStateDependency;

use Hypervel\Support\Attributes\BootOnly;

class Registry
{
    public static array $map = [];

    #[BootOnly(whenNotDefault: 'map')]
    public static function map(array $map = Defaults::MAP): void
    {
        static::$map = $map;
    }
}
