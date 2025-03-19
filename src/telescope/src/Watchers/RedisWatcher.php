<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Collection\Collection;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Redis\Event\CommandExecuted;
use Hyperf\Redis\Redis;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

class RedisWatcher extends Watcher
{
    /**
     * Indicates if the redis event is enabled.
     */
    protected static bool $eventsEnabled = false;

    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        if (! static::$eventsEnabled || ! $app->has(Redis::class)) {
            return;
        }

        $app->get(EventDispatcherInterface::class)
            ->listen(CommandExecuted::class, [$this, 'recordCommand']);
    }

    /**
     * Enable Redis events.
     * This function needs to be called before the Redis connection is created.
     */
    public static function enableRedisEvents(ContainerInterface $app): void
    {
        $config = $app->get(ConfigInterface::class);
        foreach (array_keys($config->get('redis', [])) as $connection) {
            $config->set("redis.{$connection}.event.enable", true);
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
