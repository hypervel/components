<?php

declare(strict_types=1);

namespace Hypervel\Redis\Events;

use Hypervel\Redis\RedisConnection;
use Throwable;

class CommandExecuted
{
    /**
     * Create a new event instance.
     *
     * @param float $time duration in milliseconds
     */
    public function __construct(
        public string $command,
        public array $parameters,
        public ?float $time,
        public RedisConnection $connection,
        public string $connectionName,
        public mixed $result,
        public ?Throwable $throwable,
    ) {
    }

    /**
     * Get the formatted command string.
     */
    public function getFormatCommand(): string
    {
        $parameters = collect($this->parameters)->map(function ($parameter) {
            if (is_array($parameter)) {
                return collect($parameter)->map(function ($value, $key) {
                    if (is_array($value)) {
                        return sprintf('%s %s', $key, json_encode($value));
                    }

                    return is_int($key) ? $value : sprintf('%s %s', $key, $value);
                })->implode(' ');
            }

            return $parameter;
        })->implode(' ');

        return sprintf('%s %s', $this->command, $parameters);
    }
}
