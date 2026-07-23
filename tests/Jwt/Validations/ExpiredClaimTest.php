<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Validations;

use Hypervel\Jwt\Exceptions\TokenExpiredException;
use Hypervel\Jwt\Validations\ExpiredClaim;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\TestCase;

class ExpiredClaimTest extends TestCase
{
    public function testValid(): void
    {
        CarbonImmutable::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectNotToPerformAssertions();

        $validation = new ExpiredClaim(['leeway' => 3600]);

        $validation->validate([]);
        $validation->validate(['exp' => Date::now()->timestamp + 3600]);
        $validation->validate(['exp' => Date::now()->timestamp - 3600]);
    }

    public function testInvalid(): void
    {
        CarbonImmutable::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectException(TokenExpiredException::class);
        $this->expectExceptionMessage('Token has expired');

        $validation = new ExpiredClaim;

        $validation->validate(['exp' => Date::now()->timestamp - 3600]);
    }
}
