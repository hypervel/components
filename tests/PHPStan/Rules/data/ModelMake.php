<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules\Data;

// Hypervel: upstream's App\User boots SoftDeletes and has a default connection, which the rule leaves silent.

class ModelMake
{
    use ModelMakeTrait;

    public function make(): ModelMakeTarget
    {
        return ModelMakeTarget::make();
    }

    public function makeStringClass(): ModelMakeTarget
    {
        $class = ModelMakeTarget::class;

        return $class::make();
    }

    public function makeFromTrait(): ModelMakeTarget
    {
        return $this->makeInTrait();
    }
}
