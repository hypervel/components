<?php

declare(strict_types=1);

namespace Hypervel\Tests\Support;

use Hypervel\Support\Benchmark;
use Hypervel\Tests\TestCase;

class SupportBenchmarkTest extends TestCase
{
    public function testMeasure()
    {
        $this->assertIsNumeric(Benchmark::measure(fn () => 1 + 1));

        $this->assertIsArray(Benchmark::measure([
            'first' => fn () => 1 + 1,
            'second' => fn () => 2 + 2,
        ], 3));
    }

    public function testValue()
    {
        $this->assertIsArray(Benchmark::value(fn () => 1 + 1));
    }

    public function testMacroable()
    {
        $macroName = __FUNCTION__;

        $this->assertFalse(Benchmark::hasMacro($macroName));

        // Register a macro to test
        Benchmark::macro($macroName, fn () => true);

        $this->assertTrue(Benchmark::hasMacro($macroName));
        $this->assertTrue(Benchmark::$macroName());
    }
}
