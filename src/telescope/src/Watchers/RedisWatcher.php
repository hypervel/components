<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Event\Dispatcher;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\Redis;
use Hypervel\Redis\RedisConfig;
use Hypervel\Support\Collection;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;

class RedisWatcher extends Watcher
{
    /**
     * Indicates if the redis event is enabled.
     */
    protected static bool $eventsEnabled = false;

    /**
     * Register the watcher.
     */
    public function register(Container $app): void
    {
        if (! static::$eventsEnabled || ! $app->has(Redis::class)) {
            return;
        }

        $app->make(Dispatcher::class)
            ->listen(CommandExecuted::class, [$this, 'recordCommand']);
    }

    /**
     * Enable Redis events.
     * This function needs to be called before the Redis connection is created.
     */
    public static function enableRedisEvents(Container $app): void
    {
        $config = $app->make('config');
        $redisConfig = $app->make(RedisConfig::class);
        foreach ($redisConfig->connectionNames() as $connection) {
            $config->set("database.redis.{$connection}.event.enable", true);
        }

        static::$eventsEnabled = true;
    }

    /**
     * Record a Redis command was executed.
     */
    public function recordCommand(CommandExecuted $event): void
    {
        if (! Telescope::isRecording() || $this->shouldIgnore($event)) {
            return;
        }

        Telescope::recordRedis(IncomingEntry::make([
            'connection' => $event->connectionName,
            'command' => $this->formatCommand($event->command, $event->parameters),
            'time' => number_format($event->time, 2, '.', ''),
        ]));
    }

    /**
     * Format the given Redis command.
     */
    private function formatCommand(string $command, array $parameters): string
    {
        $parameters = Collection::make($parameters)->map(function ($parameter) {
            if (is_array($parameter)) {
                return Collection::make($parameter)->map(function ($value, $key) {
                    if (is_array($value)) {
                        return json_encode($value);
                    }

                    return is_int($key) ? $value : "{$key} {$value}";
                })->implode(' ');
            }

            return $parameter;
        })->implode(' ');

        return "{$command} {$parameters}";
    }

    /**
     * Determine if the event should be ignored.
     */
    private function shouldIgnore(mixed $event): bool
    {
        return in_array($event->command, [
            'pipeline',
            'transaction',
        ]);
    }
}
