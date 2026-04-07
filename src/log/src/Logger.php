<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Support\Traits\Conditionable;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

class Logger implements LoggerInterface
{
    use Conditionable;

    /**
     * The CoroutineContext key prefix for per-instance logger context.
     */
    protected const CONTEXT_KEY_PREFIX = '__log.channel_context.';

    /**
     * The coroutine-local key for this logger instance's context.
     *
     * Each Logger gets its own CoroutineContext slot so that
     * withContext() on one channel does not leak into others.
     */
    protected readonly string $contextKey;

    /**
     * Create a new log writer instance.
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected ?Dispatcher $dispatcher = null
    ) {
        $this->contextKey = self::CONTEXT_KEY_PREFIX . spl_object_id($this);
    }

    /**
     * Log an emergency message to the logs.
     */
    public function emergency(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log an alert message to the logs.
     */
    public function alert(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a critical message to the logs.
     */
    public function critical(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log an error message to the logs.
     */
    public function error(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a warning message to the logs.
     */
    public function warning(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a notice to the logs.
     */
    public function notice(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log an informational message to the logs.
     */
    public function info(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a debug message to the logs.
     */
    public function debug(Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a message to the logs.
     *
     * @param string $level
     */
    public function log($level, Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context);
    }

    /**
     * Dynamically pass log calls into the writer.
     */
    public function write(string $level, Arrayable|Jsonable|Stringable|array|string $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context);
    }

    /**
     * Write a message to the log.
     */
    protected function writeLog(string $level, Arrayable|Jsonable|Stringable|array|string $message, array $context): void
    {
        if (method_exists($this->logger, 'isHandling') && ! $this->logger->isHandling($level)) {
            return;
        }

        $this->logger->{$level}(
            $message = $this->formatMessage($message),
            $context = array_merge($this->getContext(), $context)
        );

        $this->fireLogEvent($level, $message, $context);
    }

    /**
     * Add context to all future logs.
     *
     * @return $this
     */
    public function withContext(array $context = []): self
    {
        CoroutineContext::override($this->contextKey, function ($currentContext) use ($context) {
            return array_merge($currentContext ?: [], $context);
        });

        return $this;
    }

    /**
     * Flush the log context on all currently resolved channels.
     *
     * @param null|string[] $keys
     * @return $this
     */
    public function withoutContext(?array $keys = null): self
    {
        if (is_array($keys)) {
            CoroutineContext::override($this->contextKey, function ($currentContext) use ($keys) {
                return array_diff_key($currentContext ?: [], array_flip($keys));
            });
        } else {
            CoroutineContext::forget($this->contextKey);
        }

        return $this;
    }

    /**
     * Get the existing context array.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return (array) CoroutineContext::get($this->contextKey, []);
    }

    /**
     * Register a new callback handler for when a log event is triggered.
     *
     * @throws RuntimeException
     */
    public function listen(Closure $callback): void
    {
        if (! isset($this->dispatcher)) {
            throw new RuntimeException('Events dispatcher has not been set.');
        }

        $this->dispatcher->listen(MessageLogged::class, $callback);
    }

    /**
     * Fire a log event.
     */
    protected function fireLogEvent(string $level, string $message, array $context = []): void
    {
        // Avoid dispatching the event multiple times if our logger instance is the LogManager...
        if ($this->logger instanceof LogManager
            && $this->logger->getEventDispatcher() !== null) {
            return;
        }

        // If the event dispatcher is set, we will pass along the parameters to the
        // log listeners. These are useful for building profilers or other tools
        // that aggregate all of the log messages for a given "request" cycle.
        if ($this->dispatcher?->hasListeners(MessageLogged::class)) {
            $this->dispatcher->dispatch(new MessageLogged($level, $message, $context));
        }
    }

    /**
     * Format the parameters for the logger.
     */
    protected function formatMessage(Arrayable|Jsonable|Stringable|array|string $message): string
    {
        return match (true) {
            is_array($message) => var_export($message, true),
            $message instanceof Jsonable => $message->toJson(),
            $message instanceof Arrayable => var_export($message->toArray(), true),
            default => (string) $message,
        };
    }

    /**
     * Get the underlying logger implementation.
     */
    public function getLogger(): LoggerInterface
    {
        return $this->logger;
    }

    /**
     * Get the event dispatcher instance.
     */
    public function getEventDispatcher(): ?Dispatcher
    {
        return $this->dispatcher;
    }

    /**
     * Set the event dispatcher instance.
     */
    public function setEventDispatcher(Dispatcher $dispatcher): void
    {
        $this->dispatcher = $dispatcher;
    }

    /**
     * Dynamically proxy method calls to the underlying logger.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->logger->{$method}(...$parameters);
    }
}
