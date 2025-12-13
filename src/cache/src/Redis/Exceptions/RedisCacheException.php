<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Exceptions;

use RuntimeException;

/**
 * Exception thrown when Redis cache operations fail.
 *
 * This exception is used for errors specific to the Redis cache functionality,
 * such as missing Redis 8.0+ features required for any tagging (HSETEX/HEXPIRE
 * commands) or cluster cross-slot errors.
 */
class RedisCacheException extends RuntimeException
{
}
