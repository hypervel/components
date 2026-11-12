<?php

namespace Benchmark;

use Hypervel\Support\Benchmark;

use function PHPStan\Testing\assertType;

function test(): void
{
    assertType('float', Benchmark::measure(fn () => 'Hello World'));
    // Hypervel: an array's averages are not cast to float, so a whole-millisecond average stays an integer.
    assertType('array<0|1, float|int>', Benchmark::measure([fn () => 'Hello World', fn () => 'Hello World']));
    assertType('array<\'test1\'|\'test2\', float|int>', Benchmark::measure(['test1' => fn () => 'Hello World', 'test2' => fn () => 'Hello World']));
    assertType('array<100|\'test\', float|int>', Benchmark::measure(['test' => fn () => 'Hello World', 100 => fn () => 'Hello World']));
}
