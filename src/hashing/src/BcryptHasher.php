<?php

declare(strict_types=1);

namespace Hypervel\Hashing;

use Hypervel\Hashing\Contracts\Hasher as HasherContract;
use RuntimeException;

class BcryptHasher extends AbstractHasher implements HasherContract
{
    /**
     * The default cost factor.
     */
    protected int $rounds = 10;

    /**
     * Indicates whether to perform an algorithm check.
     */
    protected bool $verifyAlgorithm = false;

    /**
     * Create a new hasher instance.
     */
    public function __construct(array $options = [])
    {
        $this->rounds = (int) ($options['rounds'] ?? $this->rounds);
        $this->verifyAlgorithm = $options['verify'] ?? $this->verifyAlgorithm;
    }

    /**
     * Hash the given value.
     *
     * @throws RuntimeException
     */
    public function make(string $value, array $options = []): string
    {
        $hash = password_hash($value, PASSWORD_BCRYPT, [
            'cost' => $this->cost($options),
        ]);

        if ($hash === false) { // @phpstan-ignore identical.alwaysFalse (PHP 8 throws instead, kept for safety)
            throw new RuntimeException('Bcrypt hashing not supported.');
        }

        return $hash;
    }

    /**
     * Check the given plain value against a hash.
     *
     * @throws RuntimeException
     */
    public function check(string $value, ?string $hashedValue, array $options = []): bool
    {
        if ($this->verifyAlgorithm && $this->info($hashedValue)['algoName'] !== 'bcrypt') {
            throw new RuntimeException('This password does not use the Bcrypt algorithm.');
        }

        return parent::check($value, $hashedValue, $options);
    }

    /**
     * Check if the given hash has been hashed using the given options.
     */
    public function needsRehash(string $hashedValue, array $options = []): bool
    {
        return password_needs_rehash($hashedValue, PASSWORD_BCRYPT, [
            'cost' => $this->cost($options),
        ]);
    }

    /**
     * Verify that the configuration of the given hash is less than or equal
     * to the current hasher configuration.
     *
     * @internal
     */
    public function verifyConfiguration(string $hashedValue): bool
    {
        return $this->isUsingCorrectAlgorithm($hashedValue) && $this->isUsingValidOptions($hashedValue);
    }

    /**
     * Verify the hashed value's algorithm.
     */
    protected function isUsingCorrectAlgorithm(string $hashedValue): bool
    {
        return $this->info($hashedValue)['algoName'] === 'bcrypt';
    }

    /**
     * Verify the hashed value's options are valid (cost is not greater than configured).
     */
    protected function isUsingValidOptions(string $hashedValue): bool
    {
        ['options' => $options] = $this->info($hashedValue);

        if (! is_int($options['cost'] ?? null)) {
            return false;
        }

        if ($options['cost'] > $this->rounds) {
            return false;
        }

        return true;
    }

    /**
     * Set the default password work factor.
     *
     * @return $this
     */
    public function setRounds(int $rounds): static
    {
        $this->rounds = (int) $rounds;

        return $this;
    }

    /**
     * Extract the cost value from the options array.
     */
    protected function cost(array $options = []): int
    {
        return $options['rounds'] ?? $this->rounds;
    }
}
