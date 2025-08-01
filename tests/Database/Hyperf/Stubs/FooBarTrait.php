<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

trait FooBarTrait
{
    public $fooBarIsInitialized = false;

    public function initializeFooBarTrait()
    {
        $this->fooBarIsInitialized = true;
    }
}
