<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Support\Arr;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Psr\Log\LogLevel;
use Throwable;

class LogWatcher extends Watcher
{
    /**
     * The available log level priorities.
     */
    protected const PRIORITIES = [
        LogLevel::DEBUG => 100,
        LogLevel::INFO => 200,
        LogLevel::NOTICE => 250,
        LogLevel::WARNING => 300,
        LogLevel::ERROR => 400,
        LogLevel::CRITICAL => 500,
        LogLevel::ALERT => 550,
        LogLevel::EMERGENCY => 600,
    ];

    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        $app->make(Dispatcher::class)
            ->listen(MessageLogged::class, [$this, 'recordLog']);
    }

    /**
     * Record a message was logged.
     */
    public function recordLog(MessageLogged $event): void
    {
        if (! Telescope::isRecording() || $this->shouldIgnore($event)) {
            return;
        }

        $context = Arr::except($event->context, ['telescope']);

        $content = [
            'level' => $event->level,
            'message' => $this->interpolate((string) $event->message, $context),
            'context' => $context,
        ];

        if ($event->extra) {
            $content['extra'] = $event->extra;
        }

        Telescope::recordLog(
            IncomingEntry::make($content)->tags($this->tags($event))
        );
    }

    /**
     * Interpolate the given message with the given context values.
     */
    private function interpolate(string $message, array $context): string
    {
        $replace = [];

        foreach ($context as $key => $val) {
            if (is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                $replace['{' . $key . '}'] = $val;
            }
        }

        return strtr($message, $replace);
    }

    /**
     * Extract tags from the given event.
     */
    private function tags(MessageLogged $event): array
    {
        return $event->context['telescope'] ?? [];
    }

    /**
     * Determine if the event should be ignored.
     */
    private function shouldIgnore(mixed $event): bool
    {
        if (isset($event->context['exception']) && $event->context['exception'] instanceof Throwable) {
            return true;
        }

        $minimumTelescopeLogLevel = static::PRIORITIES[$this->options['level'] ?? 'debug']
            ?? static::PRIORITIES[LogLevel::DEBUG];

        $eventLogLevel = static::PRIORITIES[$event->level]
            ?? static::PRIORITIES[LogLevel::DEBUG];

        return $eventLogLevel < $minimumTelescopeLogLevel;
    }
}
