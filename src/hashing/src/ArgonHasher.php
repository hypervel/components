<?php

declare(strict_types=1);

namespace Hypervel\Hashing;

use Hypervel\Hashing\Contracts\Hasher as HasherContract;
use RuntimeException;

class ArgonHasher extends AbstractHasher implements HasherContract
{
    /**
     * The default memory cost factor.
     */
    protected int $memory = 1024;

    /**
     * The default time cost factor.
     */
    protected int $time = 2;

    /**
     * The default threads factor.
     */
    protected int $threads = 2;

    /**
     * Indicates whether to perform an algorithm check.
     */
    protected bool $verifyAlgorithm = false;

    /**
     * Create a new hasher instance.
     */
    public function __construct(array $options = [])
    {
        $this->time = $options['time'] ?? $this->time;
        $this->memory = $options['memory'] ?? $this->memory;
        $this->threads = $this->threads($options);
        $this->verifyAlgorithm = $options['verify'] ?? $this->verifyAlgorithm;
    }

    /**
     * Hash the given value.
     *
     * @throws RuntimeException
     */
    public function make(string $value, array $options = []): string
    {
        $hash = @password_hash($value, $this->algorithm(), [
            'memory_cost' => $this->memory($options),
            'time_cost' => $this->time($options),
            'threads' => $this->threads($options),
        ]);

        if (! is_string($hash)) {
            throw new RuntimeException('Argon2 hashing not supported.');
        }

        return $hash;
    }

    /**
     * Get the algorithm that should be used for hashing.
     */
    protected function algorithm(): int|string
    {
        return PASSWORD_ARGON2I;
    }

    /**
     * Check the given plain value against a hash.
     *
     * @throws RuntimeException
     */
    public function check(string $value, ?string $hashedValue, array $options = []): bool
    {
        if ($this->verifyAlgorithm && $this->info($hashedValue)['algoName'] !== 'argon2i') {
            throw new RuntimeException('This password does not use the Argon2i algorithm.');
        }

        return parent::check($value, $hashedValue, $options);
    }

    /**
     * Check if the given hash has been hashed using the given options.
     */
    public function needsRehash(string $hashedValue, array $options = []): bool
    {
        return password_needs_rehash($hashedValue, $this->algorithm(), [
            'memory_cost' => $this->memory($options),
            'time_cost' => $this->time($options),
            'threads' => $this->threads($options),
        ]);
    }

    /**
     * Set the default password memory factor.
     *
     * @return $this
     */
    public function setMemory(int $memory): static
    {
        $this->memory = $memory;

        return $this;
    }

    /**
     * Set the default password timing factor.
     *
     * @return $this
     */
    public function setTime(int $time): static
    {
        $this->time = $time;

        return $this;
    }

    /**
     * Set the default password threads factor.
     *
     * @return $this
     */
    public function setThreads(int $threads): static
    {
        $this->threads = $threads;

        return $this;
    }

    /**
     * Extract the memory cost value from the options array.
     */
    protected function memory(array $options): int
    {
        return $options['memory'] ?? $this->memory;
    }

    /**
     * Extract the time cost value from the options array.
     */
    protected function time(array $options): int
    {
        return $options['time'] ?? $this->time;
    }

    /**
     * Extract the thread's value from the options array.
     */
    protected function threads(array $options): int
    {
        if (defined('PASSWORD_ARGON2_PROVIDER') && PASSWORD_ARGON2_PROVIDER === 'sodium') {
            return 1;
        }

        return $options['threads'] ?? $this->threads;
    }
}
