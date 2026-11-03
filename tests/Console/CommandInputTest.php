<?php

declare(strict_types=1);

namespace Hypervel\Tests\Console;

use Hypervel\Console\CommandInput;
use Hypervel\Tests\Support\Concerns\TestsInteractsWithDataContract;
use Hypervel\Tests\TestCase;

class CommandInputTest extends TestCase
{
    use TestsInteractsWithDataContract;

    public function testExistsMethod(): void
    {
        $this->assertDataExistsContract();
        $this->assertDataExistsNestedContract();
    }

    public function testHasMethod(): void
    {
        $this->assertDataHasContract();
        $this->assertDataHasNestedContract();
    }

    public function testHasAnyMethod(): void
    {
        $this->assertDataHasAnyContract();
        $this->assertDataHasAnyNestedContract();
    }

    public function testWhenHasMethod(): void
    {
        $this->assertDataWhenHasContract();
        $this->assertDataWhenHasNestedContract();
    }

    public function testFilledMethod(): void
    {
        $this->assertDataFilledContract();
        $this->assertDataFilledNestedContract();
    }

    public function testIsNotFilledMethod(): void
    {
        $this->assertDataIsNotFilledContract();
        $this->assertDataIsNotFilledNestedContract();
    }

    public function testAnyFilledMethod(): void
    {
        $this->assertDataAnyFilledContract();
        $this->assertDataAnyFilledNestedContract();
    }

    public function testWhenFilledMethod(): void
    {
        $this->assertDataWhenFilledContract();
        $this->assertDataWhenFilledNestedContract();
    }

    public function testWhenEnumMethod(): void
    {
        $this->assertDataWhenEnumContract();
    }

    public function testMissingMethod(): void
    {
        $this->assertDataMissingContract();
        $this->assertDataMissingNestedContract();
    }

    public function testWhenMissingMethod(): void
    {
        $this->assertDataWhenMissingContract();
        $this->assertDataWhenMissingNestedContract();
    }

    public function testStrMethod(): void
    {
        $this->assertDataStrContract();
    }

    public function testStringMethod(): void
    {
        $this->assertDataStringContract();
        $this->assertDataStringStrictContract();
    }

    public function testBooleanMethod(): void
    {
        $this->assertDataBooleanContract();
        $this->assertDataBooleanStrictContract();
        $this->assertDataBooleanStrictNativeValuesContract();
    }

    public function testIntegerMethod(): void
    {
        $this->assertDataIntegerContract();
        $this->assertDataIntegerStrictContract();
    }

    public function testFloatMethod(): void
    {
        $this->assertDataFloatContract();
        $this->assertDataFloatStrictContract();
    }

    public function testClampMethod(): void
    {
        $this->assertDataClampContract();
    }

    public function testDateMethod(): void
    {
        $this->assertDataDateContract();
    }

    public function testIntervalMethod(): void
    {
        $this->assertDataIntervalContract();
    }

    public function testEnumMethod(): void
    {
        $this->assertDataEnumContract();
        $this->assertDataEnumNestedContract();
    }

    public function testEnumsMethod(): void
    {
        $this->assertDataEnumsContract();
    }

    public function testArrayMethod(): void
    {
        $this->assertDataArrayContract();
        $this->assertDataArrayArrayableContract();
    }

    public function testCollectMethod(): void
    {
        $this->assertDataCollectContract();
        $this->assertDataCollectNestedContract();
    }

    public function testOnlyMethod(): void
    {
        $this->assertDataOnlyContract();
        $this->assertDataOnlyNestedContract();
    }

    public function testExceptMethod(): void
    {
        $this->assertDataExceptContract();
        $this->assertDataExceptNestedContract();
    }

    public function testNullValues(): void
    {
        $this->assertDataNullValuesContract();
        $this->assertDataNullValuesIgnoreDefaultsContract();
        $this->assertDataNestedNullValuesContract();
    }

    protected static function arrayToDataFactory(array $data): object
    {
        return new CommandInput($data);
    }
}
