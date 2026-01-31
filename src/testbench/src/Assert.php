<?php

declare(strict_types=1);

namespace Hypervel\Testbench;

use ArrayAccess;
use Hypervel\Testbench\Constraints\ArraySubset;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @internal This class is not meant to be used or overwritten outside the framework itself.
 */
abstract class Assert extends PHPUnit
{
    /**
     * Asserts that an array has a specified subset.
     */
    public static function assertArraySubset(
        ArrayAccess|array $subset,
        ArrayAccess|array $array,
        bool $checkForIdentity = false,
        string $msg = ''
    ): void {
        $constraint = new ArraySubset($subset, $checkForIdentity);

        PHPUnit::assertThat($array, $constraint, $msg);
    }
}
