<?php

declare(strict_types=1);

namespace Hypervel\ObjectPool;

use InvalidArgumentException;

final readonly class PoolOptions
{
    public const DEFAULT_IDLE_TTL = 300.0;

    private const DEFAULT_MIN_RETAINED_OBJECTS = 1;

    private const DEFAULT_MAX_OBJECTS = 10;

    private const DEFAULT_WAIT_TIMEOUT = 3.0;

    private const DEFAULT_MAX_LIFETIME = 60.0;

    private const DEFAULT_MAX_IDLE_TIME = 0.0;

    /**
     * Create normalized pool options.
     */
    private function __construct(
        public int $minRetainedObjects,
        public int $maxObjects,
        public float $waitTimeout,
        public float $maxLifetime,
        public float $maxIdleTime,
        public ?float $idleTtl,
    ) {
    }

    /**
     * Create normalized pool options from configuration.
     */
    public static function fromArray(array $options): self
    {
        $knownOptions = [
            'min_retained_objects',
            'max_objects',
            'wait_timeout',
            'max_lifetime',
            'max_idle_time',
            'idle_ttl',
        ];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);

        if ($unknownOptions !== []) {
            throw new InvalidArgumentException(
                'Unknown pool option(s) [' . implode(', ', $unknownOptions) . ']. Known options are ['
                . implode(', ', $knownOptions) . '].'
            );
        }

        $minRetainedObjects = self::integerOption(
            $options,
            'min_retained_objects',
            self::DEFAULT_MIN_RETAINED_OBJECTS
        );
        $maxObjects = self::integerOption($options, 'max_objects', self::DEFAULT_MAX_OBJECTS);
        $waitTimeout = self::durationOption($options, 'wait_timeout', self::DEFAULT_WAIT_TIMEOUT);
        $maxLifetime = self::durationOption($options, 'max_lifetime', self::DEFAULT_MAX_LIFETIME);
        $maxIdleTime = self::durationOption($options, 'max_idle_time', self::DEFAULT_MAX_IDLE_TIME);
        $idleTtl = self::nullableDurationOption($options, 'idle_ttl', self::DEFAULT_IDLE_TTL);

        if ($minRetainedObjects < 0) {
            throw new InvalidArgumentException('Pool option [min_retained_objects] must be at least 0.');
        }

        if ($maxObjects < 1) {
            throw new InvalidArgumentException('Pool option [max_objects] must be at least 1.');
        }

        if ($minRetainedObjects > $maxObjects) {
            throw new InvalidArgumentException(
                'Pool option [min_retained_objects] must not exceed [max_objects].'
            );
        }

        if ($waitTimeout <= 0.0) {
            throw new InvalidArgumentException('Pool option [wait_timeout] must be greater than 0.');
        }

        if ($maxLifetime < 0.0) {
            throw new InvalidArgumentException('Pool option [max_lifetime] must be at least 0.');
        }

        if ($maxIdleTime < 0.0) {
            throw new InvalidArgumentException('Pool option [max_idle_time] must be at least 0.');
        }

        if ($idleTtl !== null && $idleTtl <= 0.0) {
            throw new InvalidArgumentException('Pool option [idle_ttl] must be null or greater than 0.');
        }

        return new self(
            $minRetainedObjects,
            $maxObjects,
            $waitTimeout,
            $maxLifetime,
            $maxIdleTime,
            $idleTtl,
        );
    }

    /**
     * Determine if these options equal another normalized option set.
     */
    public function equals(self $other): bool
    {
        return $this->minRetainedObjects === $other->minRetainedObjects
            && $this->maxObjects === $other->maxObjects
            && $this->waitTimeout === $other->waitTimeout
            && $this->maxLifetime === $other->maxLifetime
            && $this->maxIdleTime === $other->maxIdleTime
            && $this->idleTtl === $other->idleTtl;
    }

    /**
     * Get the normalized options as an array.
     *
     * @return array{
     *     min_retained_objects: int,
     *     max_objects: int,
     *     wait_timeout: float,
     *     max_lifetime: float,
     *     max_idle_time: float,
     *     idle_ttl: ?float
     * }
     */
    public function toArray(): array
    {
        return [
            'min_retained_objects' => $this->minRetainedObjects,
            'max_objects' => $this->maxObjects,
            'wait_timeout' => $this->waitTimeout,
            'max_lifetime' => $this->maxLifetime,
            'max_idle_time' => $this->maxIdleTime,
            'idle_ttl' => $this->idleTtl,
        ];
    }

    /**
     * Read an integer option without accepting numeric strings or booleans.
     */
    private static function integerOption(array $options, string $name, int $default): int
    {
        $value = array_key_exists($name, $options) ? $options[$name] : $default;

        if (! is_int($value)) {
            throw new InvalidArgumentException("Pool option [{$name}] must be an integer.");
        }

        return $value;
    }

    /**
     * Read and normalize a finite duration option.
     */
    private static function durationOption(array $options, string $name, float $default): float
    {
        $value = array_key_exists($name, $options) ? $options[$name] : $default;

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Pool option [{$name}] must be an integer or float.");
        }

        $value = (float) $value;

        if (! is_finite($value)) {
            throw new InvalidArgumentException("Pool option [{$name}] must be finite.");
        }

        return $value;
    }

    /**
     * Read and normalize a nullable finite duration option.
     */
    private static function nullableDurationOption(array $options, string $name, float $default): ?float
    {
        if (array_key_exists($name, $options) && $options[$name] === null) {
            return null;
        }

        return self::durationOption($options, $name, $default);
    }
}
