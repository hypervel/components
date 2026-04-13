<?php

declare(strict_types=1);

namespace Hypervel\Redis;

use RedisSentinel;

class RedisSentinelFactory
{
    protected bool $isOlderThan6 = false;

    /**
     * Create a new Redis sentinel factory instance.
     */
    public function __construct()
    {
        $this->isOlderThan6 = version_compare(phpversion('redis'), '6.0.0', '<');
    }

    /**
     * Create a redis sentinel client instance.
     *
     * @param array<string, mixed> $options
     */
    public function create(array $options = []): RedisSentinel
    {
        if ($this->isOlderThan6) {
            return new RedisSentinel(
                $options['host'],
                (int) $options['port'],
                (float) $options['connectTimeout'],
                $options['persistent'],
                (int) $options['retryInterval'],
                (float) $options['readTimeout'],
                ...(isset($options['auth']) ? [$options['auth']] : []),
            );
        }

        // https://github.com/phpredis/phpredis/blob/develop/sentinel.md#examples-for-version-60-or-later
        return new RedisSentinel($options); /* @phpstan-ignore-line */
    }
}
