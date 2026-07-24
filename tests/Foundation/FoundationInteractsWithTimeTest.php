<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Hypervel\Foundation\Testing\Concerns\InteractsWithTime;
use Hypervel\Support\Carbon;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\TestCase;
use RuntimeException;

class FoundationInteractsWithTimeTest extends TestCase
{
    use InteractsWithTime;

    public function testFreezeTimeReturnsFrozenTime(): void
    {
        $actual = $this->freezeTime();

        $this->assertTrue(Carbon::hasTestNow());
        $this->assertSame(CarbonImmutable::class, $actual::class);
        $this->assertTrue(Carbon::getTestNow()->eq($actual));
    }

    public function testFreezeTimeHonorsTheMutableDateFactoryOptOut(): void
    {
        Date::use(Carbon::class);

        $actual = $this->freezeTime();

        $this->assertSame(Carbon::class, $actual::class);
        $this->assertTrue(Carbon::getTestNow()->eq($actual));
    }

    public function testFreezeTimeReturnsCallbackResult(): void
    {
        $actual = $this->freezeTime(function (): int {
            return 12345;
        });

        $this->assertSame(12345, $actual);
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function testFreezeTimeReturnsCallbackResultEvenWhenNull(): void
    {
        $actual = $this->freezeTime(function (): null {
            return null;
        });

        $this->assertNull($actual);
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function testFreezeSecondReturnsFrozenTime(): void
    {
        $actual = $this->freezeSecond();

        $this->assertTrue(Carbon::hasTestNow());
        $this->assertSame(CarbonImmutable::class, $actual::class);
        $this->assertTrue(Carbon::getTestNow()->eq($actual));
        $this->assertSame(0, $actual->milliseconds);
    }

    public function testFreezeSecondHonorsTheMutableDateFactoryOptOut(): void
    {
        Date::use(Carbon::class);

        $actual = $this->freezeSecond();

        $this->assertSame(Carbon::class, $actual::class);
        $this->assertTrue(Carbon::getTestNow()->eq($actual));
        $this->assertSame(0, $actual->milliseconds);
    }

    public function testFreezeSecondReturnsCallbackResult(): void
    {
        $actual = $this->freezeSecond(function (): int {
            return 12345;
        });

        $this->assertSame(12345, $actual);
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function testFreezeSecondReturnsCallbackResultEvenWhenNull(): void
    {
        $actual = $this->freezeSecond(function (): null {
            return null;
        });

        $this->assertNull($actual);
        $this->assertFalse(Carbon::hasTestNow());
    }

    public function testFreezeTimeRestoresRealTimeWhenTheCallbackThrows(): void
    {
        $exception = new RuntimeException('callback failed');

        try {
            $this->freezeTime(static function () use ($exception): never {
                throw $exception;
            });
            $this->fail('Expected the callback to throw.');
        } catch (RuntimeException $throwable) {
            $this->assertSame($exception, $throwable);
        }

        $this->assertFalse(Carbon::hasTestNow());
    }
}
