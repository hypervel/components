<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Redis\Events\CommandExecuted;
use Hypervel\Redis\RedisManager;
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
    public function register(Application $app): void
    {
        if (! static::$eventsEnabled || ! $app->bound('redis')) {
            return;
        }

        $app->make(Dispatcher::class)
            ->listen(CommandExecuted::class, [$this, 'recordCommand']);
    }

    /**
     * Enable Redis events.
     *
     * Boot-only. Must be called before Redis connections are created. Mutates
     * a worker-wide Redis event override and a static flag; runtime use races
     * across coroutines.
     *
     * This function needs to be called before the Redis connection is created.
     */
    public static function enableRedisEvents(Application $app): void
    {
        $app->make(RedisManager::class)->enableEvents();

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
        $formatted = [];

        foreach ($parameters as $parameter) {
            $formatted[] = $this->formatParameter($parameter);
        }

        return $command . ' ' . implode(' ', $formatted);
    }

    /**
     * Format one Redis command parameter.
     */
    private function formatParameter(mixed $parameter): string
    {
        if (is_array($parameter)) {
            $values = [];

            foreach ($parameter as $key => $value) {
                $formatted = $this->formatParameter($value);
                $values[] = is_int($key) ? $formatted : "{$key} {$formatted}";
            }

            return implode(' ', $values);
        }

        if ($parameter === null || is_scalar($parameter)) {
            return (string) $parameter;
        }

        return get_debug_type($parameter);
    }

    /**
     * Determine if the event should be ignored.
     */
    private function shouldIgnore(CommandExecuted $event): bool
    {
        return in_array(strtolower($event->command), ['pipeline', 'multi'], true);
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$eventsEnabled = false;
    }
}
