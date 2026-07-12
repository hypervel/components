<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Validations;

use Carbon\Carbon;
use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\Validations\NotBeforeClaim;
use Hypervel\Tests\TestCase;

class NotBeforeClaimTest extends TestCase
{
    public function testValid(): void
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectNotToPerformAssertions();

        $validation = new NotBeforeClaim(['leeway' => 3600]);

        $validation->validate([]);
        $validation->validate(['nbf' => Carbon::now()->timestamp - 3600]);
        $validation->validate(['nbf' => Carbon::now()->timestamp + 3600]);
    }

    public function testInvalid(): void
    {
        Carbon::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Not Before (nbf) timestamp cannot be in the future');

        $validation = new NotBeforeClaim;

        $validation->validate(['nbf' => Carbon::now()->timestamp + 3600]);
    }
}
