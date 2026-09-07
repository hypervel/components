<?php

declare(strict_types=1);

namespace Hypervel\Tests\Saloon\Http;

use Hypervel\Saloon\Traits\RequestProperties\HasDelay;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class RequestDelayTest extends TestCase
{
    public function testItStoresARepresentableDelay(): void
    {
        $request = $this->request()->delay(250);

        $this->assertSame(250, $request->delayMilliseconds());
    }

    public function testItRejectsNegativeDelays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('representable non-negative');

        $this->request()->delay(-1);
    }

    public function testItRejectsDelaysThatOverflowMicroseconds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('representable non-negative');

        $this->request()->delay(intdiv(PHP_INT_MAX, 1000) + 1);
    }

    /**
     * Create an object with an operation-owned request delay.
     */
    protected function request(): object
    {
        return new class {
            use HasDelay;
        };
    }
}
