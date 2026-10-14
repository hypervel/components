<?php

declare(strict_types=1);

namespace Hypervel\Passport\Bridge;

use League\OAuth2\Server\CryptKey;
use SensitiveParameter;

class SigningCryptKey extends CryptKey
{
    /**
     * Expose an already validated signing key to League.
     */
    public function __construct(#[SensitiveParameter] protected readonly ParsedKey $parsedKey)
    {
        $this->keyContents = $parsedKey->contents();
        $this->keyPath = '';
        $this->passPhrase = $parsedKey->passphrase();
    }

    /**
     * Return the parsed signing key.
     */
    public function getParsedKey(): ParsedKey
    {
        return $this->parsedKey;
    }

    /**
     * Return the public key's JWK thumbprint.
     */
    public function getKeyId(): string
    {
        return $this->parsedKey->getKeyId();
    }
}
