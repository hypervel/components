<?php

declare(strict_types=1);

namespace Hypervel\Tests\Jwt\Providers;

use Hypervel\Jwt\Providers\Lcobucci\OpenSslSigner;
use Hypervel\Jwt\Providers\Lcobucci\ParsedKey;
use Hypervel\Tests\TestCase;
use Lcobucci\JWT\Signer\Ecdsa;
use Lcobucci\JWT\Signer\InvalidKeyProvided;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Signer\Rsa;
use PHPUnit\Framework\Attributes\DataProvider;

class OpenSslSignerTest extends TestCase
{
    #[DataProvider('algorithms')]
    public function testParsedAndOrdinaryKeysInteroperateWithStockLcobucci(Rsa|Ecdsa $stock, ?string $curve): void
    {
        [$private, $public, $otherPublic] = $this->keys($curve);
        $signer = new OpenSslSigner($stock);
        $parsedPrivate = ParsedKey::fromPrivateKey($private);
        $parsedPublic = ParsedKey::fromPublicKey($public);
        $privateKey = InMemory::plainText($private);
        $publicKey = InMemory::plainText($public);
        $payload = 'encoded-header.encoded-payload';

        $signature = $signer->sign($payload, $parsedPrivate);

        $this->assertSame($stock->algorithmId(), $signer->algorithmId());
        $this->assertTrue($stock->verify($signature, $payload, $publicKey));
        $this->assertTrue($signer->verify($stock->sign($payload, $privateKey), $payload, $parsedPublic));
        $this->assertTrue($stock->verify($signer->sign($payload, $privateKey), $payload, $publicKey));
        $this->assertTrue($signer->verify($signature, $payload, $publicKey));
        $this->assertFalse($signer->verify($signature, $payload, ParsedKey::fromPublicKey($otherPublic)));
        $this->assertFalse($signer->verify($signature, 'changed-payload', $parsedPublic));
        $this->assertFalse($signer->verify('wrong-length', $payload, $parsedPublic));
    }

    /**
     * Exercise each asymmetric algorithm supported by the provider.
     */
    public static function algorithms(): iterable
    {
        yield 'RS256' => [new Rsa\Sha256, null];
        yield 'RS384' => [new Rsa\Sha384, null];
        yield 'RS512' => [new Rsa\Sha512, null];
        yield 'ES256' => [new Ecdsa\Sha256, 'prime256v1'];
        yield 'ES384' => [new Ecdsa\Sha384, 'secp384r1'];
        yield 'ES512' => [new Ecdsa\Sha512, 'secp521r1'];
    }

    #[DataProvider('incompatibleKeys')]
    public function testRejectsKeysThatDoNotMeetTheAlgorithmsRequirements(Rsa|Ecdsa $stock, array $options, bool $verify): void
    {
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private);
        $parsed = $verify
            ? ParsedKey::fromPublicKey(openssl_pkey_get_details($key)['key'])
            : ParsedKey::fromPrivateKey($private);
        $signer = new OpenSslSigner($stock);

        $this->expectException(InvalidKeyProvided::class);

        if ($verify) {
            // ECDSA checks the signature length before validating the supplied key.
            $signature = str_repeat('x', $stock instanceof Ecdsa ? $stock->pointLength() : 256);
            $signer->verify($signature, 'payload', $parsed);
        } else {
            $signer->sign('payload', $parsed);
        }
    }

    /**
     * Cover lcobucci's key type and key size requirements.
     */
    public static function incompatibleKeys(): iterable
    {
        foreach ([
            'short RSA' => [new Rsa\Sha256, ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 1024]],
            'EC key for RSA' => [new Rsa\Sha256, ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']],
            'RSA key for EC' => [new Ecdsa\Sha256, ['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]],
            'wrong EC size' => [new Ecdsa\Sha384, ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']],
            'Ed25519 key for RSA' => [new Rsa\Sha256, ['private_key_type' => OPENSSL_KEYTYPE_ED25519]],
            'Ed25519 key for EC' => [new Ecdsa\Sha256, ['private_key_type' => OPENSSL_KEYTYPE_ED25519]],
        ] as $name => [$signer, $options]) {
            yield $name . ' signing' => [$signer, $options, false];
            yield $name . ' verification' => [$signer, $options, true];
        }
    }

    public function testEncryptedPrivateKeysCanBeParsedOnceAndReused(): void
    {
        [$private, $public] = $this->keys(null);
        openssl_pkey_export(openssl_pkey_get_private($private), $encrypted, 'passphrase');
        $key = ParsedKey::fromPrivateKey($encrypted, 'passphrase');
        $signer = new OpenSslSigner(new Rsa\Sha256);

        $first = $signer->sign('payload', $key);
        $second = $signer->sign('payload', $key);

        $this->assertSame($first, $second);
        $this->assertSame($encrypted, $key->contents());
        $this->assertSame('passphrase', $key->passphrase());
        $this->assertTrue($signer->verify($second, 'payload', ParsedKey::fromPublicKey($public)));
    }

    public function testMalformedPublicKeysFailWhenParsed(): void
    {
        $this->expectException(InvalidKeyProvided::class);

        ParsedKey::fromPublicKey('invalid PEM');
    }

    public function testWrongPrivateKeyPassphraseFailsWhenParsed(): void
    {
        [$private] = $this->keys(null);
        openssl_pkey_export(openssl_pkey_get_private($private), $encrypted, 'correct-passphrase');

        $this->expectException(InvalidKeyProvided::class);

        ParsedKey::fromPrivateKey($encrypted, 'incorrect-passphrase');
    }

    /**
     * Get a key pair and another public key of the same type.
     *
     * @return array{string, string, string}
     */
    protected function keys(?string $curve): array
    {
        if ($curve === null) {
            return [
                file_get_contents(__DIR__ . '/../Fixtures/keys/id_rsa'),
                file_get_contents(__DIR__ . '/../Fixtures/keys/id_rsa.pub'),
                file_get_contents(__DIR__ . '/../Fixtures/keys/id_rsa_alt.pub'),
            ];
        }

        $options = ['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => $curve];
        $key = openssl_pkey_new($options);
        openssl_pkey_export($key, $private);

        return [$private, openssl_pkey_get_details($key)['key'], openssl_pkey_get_details(openssl_pkey_new($options))['key']];
    }
}
