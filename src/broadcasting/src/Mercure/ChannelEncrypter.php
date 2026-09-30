<?php

declare(strict_types=1);

namespace Hypervel\Broadcasting\Mercure;

use InvalidArgumentException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Core\Util\Base64UrlSafe;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\Dir;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use LogicException;
use SensitiveParameter;

/**
 * Encrypts Mercure updates end to end, so the hub never sees their content.
 */
class ChannelEncrypter
{
    /**
     * The JWE builder, built on first use and reused across updates.
     */
    protected ?JWEBuilder $jweBuilder = null;

    /**
     * The compact JWE serializer.
     */
    protected ?CompactSerializer $serializer = null;

    /**
     * Create a new Mercure channel encrypter.
     */
    public function __construct(#[SensitiveParameter] protected string $key)
    {
        if (! class_exists(JWEBuilder::class)) {
            throw new LogicException('web-token/jwt-library is required to use end-to-end encrypted Mercure channels. You may install it via: composer require web-token/jwt-library');
        }

        if (strlen($key) !== 32) {
            throw new InvalidArgumentException('The Mercure channel encryption key must be exactly 32 bytes.');
        }
    }

    /**
     * Encrypt the given plaintext for the given channel, as a compact JWE.
     */
    public function encrypt(string $plaintext, string $channel): string
    {
        $this->jweBuilder ??= new JWEBuilder(new AlgorithmManager([new Dir, new A256GCM]));

        $this->serializer ??= new CompactSerializer;

        $jwe = $this->jweBuilder->create()
            ->withPayload($plaintext)
            ->withSharedProtectedHeader(['alg' => 'dir', 'enc' => 'A256GCM'])
            ->addRecipient(new JWK(['kty' => 'oct', 'k' => Base64UrlSafe::encodeUnpadded($this->channelKey($channel))]))
            ->build();

        return $this->serializer->serialize($jwe, 0);
    }

    /**
     * Derive the AES-256-GCM key of the given channel.
     */
    public function channelKey(string $channel): string
    {
        return hash_hkdf('sha256', $this->key, 32, $channel);
    }

    /**
     * Build the JSON Web Key sharing the given channel's key with an authorized subscriber.
     *
     * @return array{kty: string, k: string, alg: string, use: string}
     */
    public function channelJwk(string $channel): array
    {
        return [
            'kty' => 'oct',
            'k' => Base64UrlSafe::encodeUnpadded($this->channelKey($channel)),
            'alg' => 'A256GCM',
            'use' => 'enc',
        ];
    }
}
