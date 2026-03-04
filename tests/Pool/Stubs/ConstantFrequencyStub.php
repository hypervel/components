<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pool\Stubs;

use Hypervel\Pool\ConstantFrequency;

class ConstantFrequencyStub extends ConstantFrequency
{
    protected int $interval = 1;
}
