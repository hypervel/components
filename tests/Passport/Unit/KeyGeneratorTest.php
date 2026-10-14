<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Passport\KeyGenerator;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;

class KeyGeneratorTest extends TestCase
{
    public function testDefaultKeysAre4096BitRsaPemPairs(): void
    {
        $keys = (new KeyGenerator)->generate();
        $private = openssl_pkey_get_private($keys['private']);
        $public = openssl_pkey_get_public($keys['public']);

        $this->assertStringStartsWith('-----BEGIN PRIVATE KEY-----', $keys['private']);
        $this->assertStringStartsWith('-----BEGIN PUBLIC KEY-----', $keys['public']);
        $this->assertSame(4096, openssl_pkey_get_details($private)['bits']);
        $this->assertSame(OPENSSL_KEYTYPE_RSA, openssl_pkey_get_details($public)['type']);
        $this->assertTrue(openssl_sign('payload', $signature, $private, OPENSSL_ALGO_SHA256));
        $this->assertSame(1, openssl_verify('payload', $signature, $public, OPENSSL_ALGO_SHA256));
    }

    public function testTheRequestedKeyLengthIsUsed(): void
    {
        $keys = (new KeyGenerator)->generate(2048);

        $this->assertSame(2048, openssl_pkey_get_details(openssl_pkey_get_public($keys['public']))['bits']);
    }

    public function testKeysBelowTheSigningMinimumAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new KeyGenerator)->generate(1024);
    }
}
