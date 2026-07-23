<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Attributes;

use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Testbench\Attributes\WithImmutableDates;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\Test;

class WithImmutableDatesTest extends TestCase
{
    #[Test]
    #[WithImmutableDates]
    public function itUsesImmutableDates(): void
    {
        $date = Date::parse('2023-01-01');

        $this->assertSame(CarbonImmutable::class, $date::class);
    }

    public function testItForcesImmutableDatesAfterAMutableApplicationOptOut(): void
    {
        Date::use(Carbon::class);

        $attribute = new WithImmutableDates;
        $attribute->beforeEach($this->app);

        $this->assertSame(CarbonImmutable::class, Date::now()::class);
    }
}
