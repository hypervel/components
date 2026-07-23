<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Validations;

use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\Validations\IssuedAtClaim;
use Hypervel\Support\CarbonImmutable;
use Hypervel\Support\Facades\Date;
use Hypervel\Tests\TestCase;

class IssuedAtClaimTest extends TestCase
{
    public function testValid(): void
    {
        CarbonImmutable::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectNotToPerformAssertions();

        $validation = new IssuedAtClaim(['leeway' => 3600]);

        $validation->validate([]);
        $validation->validate(['iat' => Date::now()->timestamp - 3600]);
        $validation->validate(['iat' => Date::now()->timestamp + 3600]);
    }

    public function testInvalid(): void
    {
        CarbonImmutable::setTestNow('2000-01-01T00:00:00.000000Z');

        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Issued At (iat) timestamp cannot be in the future');

        $validation = new IssuedAtClaim;

        $validation->validate(['iat' => Date::now()->timestamp + 3600]);
    }
}
