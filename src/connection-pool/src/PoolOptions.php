<?php

declare(strict_types=1);

namespace Hypervel\ConnectionPool;

use InvalidArgumentException;

final readonly class PoolOptions
{
    /**
     * Lowest max_lifetime fraction assigned to a connection generation.
     */
    public const int MIN_LIFETIME_JITTER_BASIS = 9000;

    /**
     * Scale used for jitter basis values.
     */
    public const int LIFETIME_JITTER_SCALE = 10000;

    private const int DEFAULT_MIN_RETAINED_CONNECTIONS = 1;

    private const int DEFAULT_MAX_CONNECTIONS = 10;

    private const float DEFAULT_CONNECT_TIMEOUT = 10.0;

    private const float DEFAULT_WAIT_TIMEOUT = 3.0;

    private const float DEFAULT_HEARTBEAT_TIMEOUT = 1.0;

    private const float DEFAULT_MAX_IDLE_TIME = 60.0;

    /**
     * Create normalized pool options.
     *
     * @param list<class-string> $events
     */
    private function __construct(
        public int $minRetainedConnections,
        public int $maxConnections,
        public float $connectTimeout,
        public float $waitTimeout,
        public ?float $heartbeatInterval,
        public float $heartbeatTimeout,
        public ?float $idleCheckInterval,
        public ?float $maxIdleTime,
        public ?float $maxLifetime,
        public array $events,
    ) {
    }

    /**
     * Create normalized pool options from configuration.
     */
    public static function fromArray(array $options): self
    {
        $knownOptions = [
            'min_retained_connections',
            'max_connections',
            'connect_timeout',
            'wait_timeout',
            'heartbeat_interval',
            'heartbeat_timeout',
            'idle_check_interval',
            'max_idle_time',
            'max_lifetime',
            'events',
        ];
        $unknownOptions = array_diff(array_keys($options), $knownOptions);

        if ($unknownOptions !== []) {
            throw new InvalidArgumentException(
                'Unknown connection pool option(s) [' . implode(', ', $unknownOptions) . ']. Known options are ['
                . implode(', ', $knownOptions) . '].',
            );
        }

        $minimum = self::integerOption($options, 'min_retained_connections', self::DEFAULT_MIN_RETAINED_CONNECTIONS);
        $maximum = self::integerOption($options, 'max_connections', self::DEFAULT_MAX_CONNECTIONS);
        self::validateConnectionCounts($minimum, $maximum);

        $events = array_key_exists('events', $options) ? $options['events'] : [];

        if (! is_array($events) || ! array_is_list($events)) {
            throw new InvalidArgumentException('Pool option [events] must be a list of event class names.');
        }

        foreach ($events as $event) {
            if (! is_string($event) || ! class_exists($event)) {
                throw new InvalidArgumentException('Pool option [events] must contain existing event class names.');
            }
        }

        return new self(
            $minimum,
            $maximum,
            self::durationOption($options, 'connect_timeout', self::DEFAULT_CONNECT_TIMEOUT),
            self::durationOption($options, 'wait_timeout', self::DEFAULT_WAIT_TIMEOUT),
            self::nullableDurationOption($options, 'heartbeat_interval', null),
            self::durationOption($options, 'heartbeat_timeout', self::DEFAULT_HEARTBEAT_TIMEOUT),
            self::nullableDurationOption($options, 'idle_check_interval', null),
            self::nullableDurationOption($options, 'max_idle_time', self::DEFAULT_MAX_IDLE_TIME),
            self::nullableDurationOption($options, 'max_lifetime', null),
            $events,
        );
    }

    /**
     * Return a jittered lifetime deadline for a connection generation.
     */
    public function jitteredLifetimeDeadline(float $createdAt): ?float
    {
        if ($this->maxLifetime === null) {
            return null;
        }

        $factor = random_int(self::MIN_LIFETIME_JITTER_BASIS, self::LIFETIME_JITTER_SCALE) / self::LIFETIME_JITTER_SCALE;

        return $createdAt + ($this->maxLifetime * $factor);
    }

    /**
     * Validate the connection-count relationship.
     */
    private static function validateConnectionCounts(int $minRetainedConnections, int $maxConnections): void
    {
        if ($minRetainedConnections < 0) {
            throw new InvalidArgumentException('Pool option [min_retained_connections] must be at least 0.');
        }

        if ($maxConnections < 1) {
            throw new InvalidArgumentException('Pool option [max_connections] must be at least 1.');
        }

        if ($minRetainedConnections > $maxConnections) {
            throw new InvalidArgumentException(
                'Pool option [min_retained_connections] must not exceed [max_connections].',
            );
        }
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
     * Read and normalize a finite positive duration option.
     */
    private static function durationOption(array $options, string $name, ?float $default): float
    {
        $value = array_key_exists($name, $options) ? $options[$name] : $default;

        if (! is_int($value) && ! is_float($value)) {
            throw new InvalidArgumentException("Pool option [{$name}] must be an integer or float.");
        }

        $value = (float) $value;

        if (! is_finite($value) || $value <= 0.0) {
            throw new InvalidArgumentException("Pool option [{$name}] must be a finite number greater than 0.");
        }

        return $value;
    }

    /**
     * Read and normalize a nullable finite positive duration option.
     */
    private static function nullableDurationOption(array $options, string $name, ?float $default): ?float
    {
        if ((array_key_exists($name, $options) ? $options[$name] : $default) === null) {
            return null;
        }

        return self::durationOption($options, $name, $default);
    }
}
