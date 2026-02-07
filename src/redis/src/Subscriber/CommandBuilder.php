<?php

declare(strict_types=1);

namespace Hypervel\Redis\Subscriber;

use InvalidArgumentException;

use function count;
use function is_array;
use function is_int;
use function is_null;
use function is_string;
use function strlen;

class CommandBuilder
{
    /**
     * Build a RESP (Redis Serialization Protocol) command string.
     *
     * @param null|int|string|array<mixed> $args
     */
    public static function build(mixed $args): string
    {
        if ($args === 'ping') {
            return 'PING' . Constants::CRLF;
        }

        return match (true) {
            is_null($args) => '$-1' . Constants::CRLF,
            is_int($args) => ':' . $args . Constants::CRLF,
            is_string($args) => '$' . strlen($args) . Constants::CRLF . $args . Constants::CRLF,
            is_array($args) => (function (array $args) {
                $result = '*' . count($args) . Constants::CRLF;
                foreach ($args as $arg) {
                    $result .= static::build($arg);
                }
                return $result;
            })($args),
            default => throw new InvalidArgumentException('Invalid args'),
        };
    }
}
