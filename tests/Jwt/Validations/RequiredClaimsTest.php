<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Validations;

use Hypervel\Jwt\Exceptions\TokenInvalidException;
use Hypervel\Jwt\Validations\RequiredClaims;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class RequiredClaimsTest extends TestCase
{
    private const array PAYLOAD = ['sub' => 1, 'iss' => 'http://example.com', 'exp' => 200, 'nbf' => 100, 'iat' => 100, 'jti' => 'foo'];

    public function testValid(): void
    {
        $this->expectNotToPerformAssertions();

        (new RequiredClaims([]))->validate([]);
        (new RequiredClaims(['required_claims' => ['sub', 'iss']]))->validate(self::PAYLOAD);
        (new RequiredClaims(['required_claims' => ['sub', 'iss', 'exp', 'nbf', 'iat', 'jti']]))->validate(self::PAYLOAD);
    }

    #[DataProvider('missingClaimsProvider')]
    public function testInvalid(array $requiredClaims, string $message): void
    {
        $this->expectException(TokenInvalidException::class);
        $this->expectExceptionMessageIs($message);

        (new RequiredClaims(['required_claims' => $requiredClaims]))->validate(self::PAYLOAD);
    }

    /**
     * Provide required claims and the error each set produces.
     *
     * @return array<string, array{array<int, string>, string}>
     */
    public static function missingClaimsProvider(): array
    {
        return [
            'one missing after present claims' => [['sub', 'iss', 'exp', 'nbf', 'iat', 'jti', 'abc'], 'Claims are missing: ["abc"]'],
            'several missing' => [['foo', 'bar'], 'Claims are missing: ["foo","bar"]'],
        ];
    }
}
