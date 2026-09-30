<?php

declare(strict_types=1);

namespace Hypervel\Tests\Broadcasting;

use Hypervel\Broadcasting\Mercure\ChannelEncrypter;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\Dir;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;

class MercureChannelEncrypterTest extends TestCase
{
    /**
     * Get the fixed test encryption key.
     */
    protected function key(): string
    {
        return hash('sha256', 'mercure-encrypter-test-key', true);
    }

    public function testConstructorRejectsKeysThatAreNot32Bytes(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new ChannelEncrypter('too-short');
    }

    public function testChannelKeyIsHkdfDerivedAndChannelSpecific(): void
    {
        $encrypter = new ChannelEncrypter($this->key());

        $this->assertSame(
            hash_hkdf('sha256', $this->key(), 32, 'private-encrypted-a'),
            $encrypter->channelKey('private-encrypted-a')
        );
        $this->assertNotSame(
            $encrypter->channelKey('private-encrypted-a'),
            $encrypter->channelKey('private-encrypted-b')
        );
    }

    public function testChannelJwkExposesTheChannelKeyAsUnpaddedUrlSafeBase64(): void
    {
        $encrypter = new ChannelEncrypter($this->key());

        $jwk = $encrypter->channelJwk('private-encrypted-a');

        $this->assertSame('oct', $jwk['kty']);
        $this->assertSame('A256GCM', $jwk['alg']);
        $this->assertSame('enc', $jwk['use']);
        $this->assertMatchesRegularExpression('/\A[A-Za-z0-9_-]{43}\z/', $jwk['k']);
        $this->assertSame(
            $encrypter->channelKey('private-encrypted-a'),
            base64_decode(strtr($jwk['k'], '-_', '+/'))
        );
    }

    public function testEncryptRoundTripsThroughAStandardJweDecrypter(): void
    {
        $encrypter = new ChannelEncrypter($this->key());

        $jwe = (new CompactSerializer)->unserialize(
            $encrypter->encrypt('{"event":"Tick"}', 'private-encrypted-a')
        );

        $decrypter = new JWEDecrypter(new AlgorithmManager([new Dir, new A256GCM]));

        $this->assertTrue($decrypter->decryptUsingKey($jwe, new JWK($encrypter->channelJwk('private-encrypted-a')), 0));
        $this->assertSame('{"event":"Tick"}', $jwe->getPayload());
        $this->assertSame(['alg' => 'dir', 'enc' => 'A256GCM'], $jwe->getSharedProtectedHeader());
    }
}
