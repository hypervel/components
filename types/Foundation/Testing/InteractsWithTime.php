<?php

declare(strict_types=1);

namespace Hypervel\Types\Foundation\Testing;

use Carbon\CarbonInterface;
use Hypervel\Foundation\Testing\Concerns\InteractsWithTime;
use Hypervel\Support\CarbonImmutable;

use function PHPStan\Testing\assertType;

class InteractsWithTimeTestCase
{
    use InteractsWithTime;

    public function test(): void
    {
        assertType(CarbonInterface::class, $this->freezeTime());
        assertType('42', $this->freezeTime(fn () => 42));
        assertType('42', $this->freezeTime(fn (CarbonInterface $date) => 42));

        assertType(CarbonInterface::class, $this->freezeSecond());
        assertType('42', $this->freezeSecond(fn () => 42));
        assertType('42', $this->freezeSecond(fn (CarbonInterface $date) => 42));

        // @phpstan-ignore method.void
        assertType('null', $this->travelTo(CarbonImmutable::now(), function (): void {
        }));
        assertType('42', $this->travelTo(CarbonImmutable::now(), fn () => 42));
        assertType(CarbonImmutable::class, $this->travelTo(CarbonImmutable::now(), fn (CarbonImmutable $date) => $date));
        assertType("'2026-01-01'", $this->travelTo('2026-01-01', fn (string $date) => $date));
        assertType('null', $this->travelTo(CarbonImmutable::now()));
    }
}
