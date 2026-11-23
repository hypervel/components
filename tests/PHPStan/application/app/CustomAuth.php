<?php

namespace App;

use Hypervel\Support\Facades\Facade;

class CustomAuth extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'auth';
    }
}
