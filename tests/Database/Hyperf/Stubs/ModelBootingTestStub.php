<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Database\Model\Booted;
use Hyperf\Database\Model\Model;

class ModelBootingTestStub extends Model
{
    public function unboot()
    {
        Booted::$container[static::class] = false;
    }

    public function isBooted()
    {
        return Booted::$container[static::class] ?? false;
    }
}
