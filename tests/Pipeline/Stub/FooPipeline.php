<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pipeline\Stub;

use Hypervel\Pipeline\Pipeline;

class FooPipeline extends Pipeline
{
    protected function handleCarry(mixed $carry): mixed
    {
        $carry = parent::handleCarry($carry);
        if (is_int($carry)) {
            $carry += 2;
        }

        return $carry;
    }
}
