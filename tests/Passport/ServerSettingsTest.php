<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport;

use Hypervel\Passport\ServerSettings;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\TestWith;

class ServerSettingsTest extends TestCase
{
    #[TestWith(['http://auth.example'])]
    #[TestWith(['https://auth.example?'])]
    #[TestWith(['https://auth.example#fragment'])]
    #[TestWith(['https://user@auth.example'])]
    #[TestWith(['/issuer'])]
    #[TestWith(['https:///issuer'])]
    #[TestWith([''])]
    public function testRejectsInvalidIssuerConfiguration(string $issuer): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ServerSettings([], issuer: $issuer);
    }

    #[TestWith([null])]
    #[TestWith(['HTTPS://Auth.Example.com:443/Tenant%7Eone/'])]
    public function testPreservesExactIssuerIdentity(?string $issuer): void
    {
        $this->assertSame($issuer, (new ServerSettings([], issuer: $issuer))->issuer);
    }

    public function testRejectsANegativeMetadataCacheLifetime(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('metadata cache lifetime');

        new ServerSettings([], metadataCacheLifetime: -1);
    }
}
