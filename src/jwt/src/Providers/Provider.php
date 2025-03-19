<?php

declare(strict_types=1);

namespace Hypervel\JWT\Providers;

use Hyperf\Collection\Arr;

abstract class Provider
{
    public const ALGO_HS256 = 'HS256';

    public const ALGO_HS384 = 'HS384';

    public const ALGO_HS512 = 'HS512';

    public const ALGO_RS256 = 'RS256';

    public const ALGO_RS384 = 'RS384';

    public const ALGO_RS512 = 'RS512';

    public const ALGO_ES256 = 'ES256';

    public const ALGO_ES384 = 'ES384';

    public const ALGO_ES512 = 'ES512';

    /**
     * Constructor.
     */
    public function __construct(
        protected string $secret,
        protected string $algo,
        protected array $keys
    ) {
    }

    /**
     * Set the algorithm used to sign the token.
     *
     * @return $this
     */
    public function setAlgo(string $algo): static
    {
        $this->algo = $algo;

        return $this;
    }

    /**
     * Get the algorithm used to sign the token.
     */
    public function getAlgo(): string
    {
        return $this->algo;
    }

    /**
     * Set the secret used to sign the token.
     *
     * @return $this
     */
    public function setSecret(string $secret): static
    {
        $this->secret = $secret;

        return $this;
    }

    /**
     * Get the secret used to sign the token.
     */
    public function getSecret(): string
    {
        return $this->secret;
    }

    /**
     * Set the keys used to sign the token.
     *
     * @return $this
     */
    public function setKeys(array $keys): static
    {
        $this->keys = $keys;

        return $this;
    }

    /**
     * Get the array of keys used to sign tokens with an asymmetric algorithm.
     */
    public function getKeys(): array
    {
        return $this->keys;
    }

    /**
     * Get the public key used to sign tokens with an asymmetric algorithm.
     */
    public function getPublicKey(): ?string
    {
        return Arr::get($this->keys, 'public');
    }

    /**
     * Get the private key used to sign tokens with an asymmetric algorithm.
     */
    public function getPrivateKey(): ?string
    {
        return Arr::get($this->keys, 'private');
    }

    /**
     * Get the passphrase used to sign tokens
     * with an asymmetric algorithm.
     */
    public function getPassphrase(): ?string
    {
        return Arr::get($this->keys, 'passphrase');
    }

    /**
     * Get the key used to sign the tokens.
     */
    protected function getSigningKey(): mixed
    {
        return $this->isAsymmetric() ? $this->getPrivateKey() : $this->getSecret();
    }

    /**
     * Get the key used to verify the tokens.
     */
    protected function getVerificationKey(): mixed
    {
        return $this->isAsymmetric() ? $this->getPublicKey() : $this->getSecret();
    }

    /**
     * Determine if the algorithm is asymmetric, and thus requires a public/private key combo.
     */
    abstract protected function isAsymmetric(): bool;
}
