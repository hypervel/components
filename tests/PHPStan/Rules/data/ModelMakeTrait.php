<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

// Hypervel: upstream's App\User boots SoftDeletes and has a default connection, which the rule leaves silent.

trait ModelMakeTrait
{
    protected function makeInTrait(): ModelMakeTarget
    {
        return ModelMakeTarget::make();
    }
}
