<?php

declare(strict_types=1);

namespace Hypervel\Tests\JWT\Validations;

use Carbon\Carbon;
use Hypervel\JWT\Exceptions\TokenExpiredException;
use Hypervel\JWT\Validations\ExpiredClaim;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ExpiredClaimTest extends TestCase
{
    public function testValid()
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectNotToPerformAssertions();

        $validation = new ExpiredClaim(['leeway' => 3600]);

        $validation->validate([]);
        $validation->validate(['exp' => Carbon::now()->timestamp + 3600]);
        $validation->validate(['exp' => Carbon::now()->timestamp - 3600]);
    }

    public function testInvalid()
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectException(TokenExpiredException::class);
        $this->expectExceptionMessage('Token has expired');

        $validation = new ExpiredClaim();

        $validation->validate(['exp' => Carbon::now()->timestamp - 3600]);
    }
}
