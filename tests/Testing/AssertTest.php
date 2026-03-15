<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing;

use Hypervel\Testing\Assert;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\ExpectationFailedException;
use stdClass;

/**
 * @internal
 * @coversNothing
 */
class AssertTest extends TestCase
{
    public function testArraySubset()
    {
        Assert::assertArraySubset([
            'string' => 'string',
            'object' => new stdClass(),
        ], [
            'int' => 1,
            'string' => 'string',
            'object' => new stdClass(),
        ]);
    }

    public function testArraySubsetMayFail()
    {
        $this->expectException(ExpectationFailedException::class);

        Assert::assertArraySubset([
            'int' => 2,
            'string' => 'string',
            'object' => new stdClass(),
        ], [
            'int' => 1,
            'string' => 'string',
            'object' => new stdClass(),
        ]);
    }

    public function testArraySubsetWithStrict()
    {
        Assert::assertArraySubset([
            'string' => 'string',
            'object' => $object = new stdClass(),
        ], [
            'int' => 1,
            'string' => 'string',
            'object' => $object,
        ], true);
    }

    public function testArraySubsetWithStrictMayFail()
    {
        $this->expectException(ExpectationFailedException::class);

        Assert::assertArraySubset([
            'string' => 'string',
            'object' => new stdClass(),
        ], [
            'int' => 1,
            'string' => 'string',
            'object' => new stdClass(),
        ], true);
    }

    // REMOVED: testArraySubsetMayFailIfArrayIsNotArray - Union type hints (ArrayAccess|array) with
    // declare(strict_types=1) make the runtime InvalidArgumentException guards redundant; PHP rejects
    // invalid types at the call site with a TypeError.

    // REMOVED: testArraySubsetMayFailIfSubsetIsNotArray - Same reason as above.
}
