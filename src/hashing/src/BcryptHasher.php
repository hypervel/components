<?php

declare(strict_types=1);

namespace Hypervel\Hashing;

use Error;
use Hypervel\Contracts\Hashing\Hasher as HasherContract;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;

class BcryptHasher extends AbstractHasher implements HasherContract
{
    /**
     * The default cost factor.
     */
    protected int $rounds = 12;

    /**
     * Indicates whether to perform an algorithm check.
     */
    protected bool $verifyAlgorithm = false;

    /**
     * The maximum allowed length of strings that can be hashed.
     */
    protected ?int $limit = null;

    /**
     * Create a new hasher instance.
     */
    public function __construct(array $options = [])
    {
        $this->rounds = (int) ($options['rounds'] ?? $this->rounds);
        $this->verifyAlgorithm = (bool) ($options['verify'] ?? $this->verifyAlgorithm);

        $limit = $options['limit'] ?? $this->limit;

        $this->limit = $limit === null ? null : (int) $limit;
    }

    /**
     * Hash the given value.
     *
     * @throws RuntimeException
     * @throws InvalidArgumentException
     */
    public function make(#[SensitiveParameter] string $value, array $options = []): string
    {
        try {
            if ($this->limit && strlen($value) > $this->limit) {
                throw new InvalidArgumentException('Value is too long to hash. Value must be less than ' . $this->limit . ' bytes.');
            }

            $hash = password_hash($value, PASSWORD_BCRYPT, [
                'cost' => $this->cost($options),
            ]);
        } catch (Error) {
            throw new RuntimeException('Bcrypt hashing not supported.');
        }

        return $hash;
    }

    /**
     * Check the given plain value against a hash.
     *
     * @throws RuntimeException
     */
    public function check(#[SensitiveParameter] string $value, ?string $hashedValue, array $options = []): bool
    {
        if (! $this->hasHash($hashedValue)) {
            return false;
        }

        if ($this->verifyAlgorithm && ! $this->isUsingCorrectAlgorithm($hashedValue)) {
            throw new RuntimeException('This password does not use the Bcrypt algorithm.');
        }

        return parent::check($value, $hashedValue, $options);
    }

    /**
     * Check if the given hash has been hashed using the given options.
     */
    public function needsRehash(?string $hashedValue, array $options = []): bool
    {
        if (! $this->hasHash($hashedValue)) {
            return false;
        }

        return password_needs_rehash($hashedValue, PASSWORD_BCRYPT, [
            'cost' => $this->cost($options),
        ]);
    }

    /**
     * Verifies that the configuration is less than or equal to what is configured.
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
     * Verify the hashed value's options.
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
     * Boot-only. Mutates the shared hasher instance held by the HashManager;
     * per-request use races across coroutines.
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
