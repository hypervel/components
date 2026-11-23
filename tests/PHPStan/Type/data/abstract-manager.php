<?php

namespace AbstractManager;

use Hypervel\Container\Container;
use Hypervel\Support\Manager;

use function PHPStan\Testing\assertType;

abstract class AbstractManager extends Manager { }

class RealManager extends AbstractManager
{
    public function getDefaultDriver(): string
    {
        return '';
    }
}

function test(): void
{
    assertType('AbstractManager\RealManager', new RealManager(new Container()));
}
