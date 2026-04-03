<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeInterface;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\Attributes\WithImmutableDates;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * @internal
 * @coversNothing
 */
class WithImmutableDatesTest extends TestCase
{
    #[Test]
    #[WithImmutableDates]
    public function itUsesImmutableDates(): void
    {
        $date = Date::parse('2023-01-01');

        $this->assertInstanceOf(CarbonInterface::class, $date);
        $this->assertInstanceOf(DateTimeInterface::class, $date);
        $this->assertInstanceOf(DateTimeImmutable::class, $date);
    }
}
