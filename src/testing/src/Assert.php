<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use ArrayAccess;
use Hypervel\Testing\Constraints\ArraySubset;
use PHPUnit\Framework\Assert as PHPUnit;

/**
 * @internal this class is not meant to be used or overwritten outside the framework itself
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
