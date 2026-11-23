<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Fixtures\Bootstrap;

class Usage
{
    /**
     * Call the aspect target and the replaced class.
     */
    public function run(AspectGreeter $aspectGreeter, ReplacedGreeter $replacedGreeter): int
    {
        $aspectGreeter->greet(42);

        return $replacedGreeter->replacementOnly();
    }
}
