<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passport\Unit;

use Hypervel\Passport\Bridge\ParsedKey;
use Hypervel\Passport\Bridge\RsaSigner;
use Hypervel\Passport\Bridge\SigningCryptKey;
use Hypervel\Tests\TestCase;
use Lcobucci\JWT\Signer\InvalidKeyProvided;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa\Sha256;

class RsaSignerTest extends TestCase
{
    /**
     * @var null|array{private: string, public: string}
     */
    protected static ?array $keys = null;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$keys === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($key, $privateKey);
            self::$keys = ['private' => $privateKey, 'public' => openssl_pkey_get_details($key)['key']];
        }
    }

    public function testParsedKeysCrossVerifyWithTheStockSigner(): void
    {
        $signer = new RsaSigner;
        $stock = new Sha256;
        $private = ParsedKey::fromPrivateKey(self::$keys['private']);
        $public = ParsedKey::fromPublicKey(self::$keys['public']);

        $signature = $signer->sign('payload', $private);

        $this->assertTrue($stock->verify($signature, 'payload', InMemory::plainText(self::$keys['public'])));
        $this->assertTrue($signer->verify($stock->sign('payload', InMemory::plainText(self::$keys['private'])), 'payload', $public));
        $this->assertFalse($signer->verify($signature, 'changed payload', $public));
        $this->assertSame($signature, $signer->sign('payload', $private));
    }

    public function testTheSuppliedKeyControlsVerification(): void
    {
        $otherKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $otherPublicKey = ParsedKey::fromPublicKey(openssl_pkey_get_details($otherKey)['key']);
        $signer = new RsaSigner;
        $signature = $signer->sign('payload', ParsedKey::fromPrivateKey(self::$keys['private']));

        $this->assertFalse($signer->verify($signature, 'payload', $otherPublicKey));
        $this->assertTrue($signer->verify($signature, 'payload', ParsedKey::fromPublicKey(self::$keys['public'])));
    }

    public function testOrdinaryKeysKeepTheSignerContract(): void
    {
        $signer = new RsaSigner;
        $signature = $signer->sign('payload', InMemory::plainText(self::$keys['private']));

        $this->assertTrue($signer->verify($signature, 'payload', InMemory::plainText(self::$keys['public'])));
        $this->assertFalse($signer->verify($signature, 'changed payload', InMemory::plainText(self::$keys['public'])));
    }

    public function testEncryptedPrivateKeysCanSign(): void
    {
        openssl_pkey_export(openssl_pkey_get_private(self::$keys['private']), $encrypted, 'test-passphrase');
        $key = ParsedKey::fromPrivateKey($encrypted, 'test-passphrase');
        $signer = new RsaSigner;

        $this->assertSame($encrypted, $key->contents());
        $this->assertSame('test-passphrase', $key->passphrase());
        $this->assertTrue($signer->verify($signer->sign('payload', $key), 'payload', ParsedKey::fromPublicKey(self::$keys['public'])));
    }

    public function testPrivateAndPublicKeysExposeTheSamePublicIdentity(): void
    {
        $private = ParsedKey::fromPrivateKey(self::$keys['private']);
        $public = ParsedKey::fromPublicKey(self::$keys['public']);
        $bridge = new SigningCryptKey($private);
        $jwk = $public->getJwk();

        $this->assertSame($private->getKeyId(), $public->getKeyId());
        $this->assertSame($private->getJwk(), $jwk);
        $this->assertSame(['e', 'kty', 'n', 'use', 'alg', 'kid'], array_keys($jwk));
        $this->assertSame('RSA', $jwk['kty']);
        $this->assertSame('RS256', $jwk['alg']);
        $this->assertSame('sig', $jwk['use']);
        $this->assertSame('AQAB', $jwk['e']);
        $this->assertSame(256, strlen(base64_decode(strtr($jwk['n'], '-_', '+/'))));
        $this->assertSame(43, strlen($jwk['kid']));
        $this->assertSame(self::$keys['public'], $private->getPublicKey());
        $this->assertSame($private, $bridge->getParsedKey());
        $this->assertSame($private->getKeyId(), $bridge->getKeyId());
        $this->assertSame(self::$keys['private'], $bridge->getKeyContents());
    }

    public function testMalformedPublicKeysAreRejected(): void
    {
        $this->expectException(InvalidKeyProvided::class);
        $this->expectExceptionMessage('It was not possible to parse your key');

        ParsedKey::fromPublicKey('invalid');
    }

    public function testMalformedPrivateKeysAreRejected(): void
    {
        $this->expectException(InvalidKeyProvided::class);

        ParsedKey::fromPrivateKey('invalid');
    }

    public function testWrongPassphrasesAreRejected(): void
    {
        openssl_pkey_export(openssl_pkey_get_private(self::$keys['private']), $encrypted, 'correct');
        $this->expectException(InvalidKeyProvided::class);

        ParsedKey::fromPrivateKey($encrypted, 'incorrect');
    }

    public function testShortKeysAreRejected(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 1024, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $this->expectException(InvalidKeyProvided::class);
        $this->expectExceptionMessage('Key provided is shorter than 2048 bits');

        ParsedKey::fromPublicKey(openssl_pkey_get_details($key)['key']);
    }

    public function testOtherKeyTypesAreRejected(): void
    {
        $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $this->expectException(InvalidKeyProvided::class);
        $this->expectExceptionMessage('"RSA", "EC" provided');

        ParsedKey::fromPublicKey(openssl_pkey_get_details($key)['key']);
    }
}
