<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Validations;

use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\Validations\RequiredClaims;
use Hypervel\Tests\TestCase;

class RequiredClaimsTest extends TestCase
{
    public function testValid()
    {
        $this->expectNotToPerformAssertions();

        (new RequiredClaims([]))->validate([]);
        (new RequiredClaims(['required_claims' => ['sub']]))->validate(['sub' => 'foo']);
    }

    public function testInvalid()
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Claims are missing: ["sub"]');

        (new RequiredClaims(['required_claims' => ['sub']]))->validate([]);
    }
}
