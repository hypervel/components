<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Validations;

use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\Validations\IssuerClaim;
use Hypervel\Tests\TestCase;

class IssuerClaimTest extends TestCase
{
    public function testValid(): void
    {
        $validation = new IssuerClaim(['issuer' => 'https://api.example.test']);

        $validation->validate(['iss' => 'https://api.example.test']);

        $this->expectNotToPerformAssertions();
    }

    public function testSkipsValidationWhenIssuerIsNotConfigured(): void
    {
        $validation = new IssuerClaim(['issuer' => null]);

        $validation->validate(['iss' => 'https://other.example.test']);

        $this->expectNotToPerformAssertions();
    }

    public function testInvalid(): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessage('Issuer is invalid');

        $validation = new IssuerClaim(['issuer' => 'https://api.example.test']);

        $validation->validate(['iss' => 'https://other.example.test']);
    }
}
