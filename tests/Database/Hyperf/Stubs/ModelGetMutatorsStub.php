<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs;

use Hyperf\Database\Model\Model;

class ModelGetMutatorsStub extends Model
{
    public static function resetMutatorCache()
    {
        static::$mutatorCache = [];
    }

    public function getFirstNameAttribute()
    {
    }

    public function getMiddleNameAttribute()
    {
    }

    public function getLastNameAttribute()
    {
    }

    public function doNotgetFirstInvalidAttribute()
    {
    }

    public function doNotGetSecondInvalidAttribute()
    {
    }

    public function doNotgetThirdInvalidAttributeEither()
    {
    }

    public function doNotGetFourthInvalidAttributeEither()
    {
    }
}
