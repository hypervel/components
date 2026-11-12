<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Type\data;

class FailsAtRuntime
{
    public function __construct()
    {
        throw new \Exception("Cannot resolve class dynamically.");
    }
}
