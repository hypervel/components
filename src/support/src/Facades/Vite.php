<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

class Vite extends Facade
{
    protected static function getFacadeAccessor()
    {
        return \Hypervel\Foundation\Vite::class;
    }
}
