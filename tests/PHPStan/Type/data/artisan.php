<?php

namespace Artisan;

use Hypervel\Support\Facades\Artisan;
use PHPStan\TrinaryLogic;
use function PHPStan\Testing\assertType;
use function PHPStan\Testing\assertVariableCertainty;

function test(): void
{
    Artisan::command('inspire', function () {
        assertVariableCertainty(TrinaryLogic::createYes(), $this);
        assertType('Hypervel\Foundation\Console\ClosureCommand', $this);
    })->describe('Foo Bar');
}
