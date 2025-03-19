<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Closure;
use Hyperf\Context\Context;
use Hyperf\Contract\Arrayable;
use Hyperf\Contract\Jsonable;
use Hypervel\Log\Events\MessageLogged;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

class Logger implements LoggerInterface
{
    /**
     * Create a new log writer instance.
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected ?EventDispatcherInterface $dispatcher = null
    ) {
    }

    /**
     * Log an emergency message to the logs.
     *
     * @param string $message
     */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log an alert message to the logs.
     *
     * @param string $message
     */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a critical message to the logs.
     *
     * @param string $message
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log an error message to the logs.
     *
     * @param string $message
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a warning message to the logs.
     *
     * @param string $message
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a notice to the logs.
     *
     * @param string $message
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log an informational message to the logs.
     *
     * @param string $message
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a debug message to the logs.
     *
     * @param string $message
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->writeLog(__FUNCTION__, $message, $context);
    }

    /**
     * Log a message to the logs.
     *
     * @param string $level
     * @param string $message
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context);
    }

    /**
     * Dynamically pass log calls into the writer.
     *
     * @param string $level
     * @param string $message
     */
    public function write($level, string|Stringable $message, array $context = []): void
    {
        $this->writeLog($level, $message, $context);
    }

    /**
     * Write a message to the log.
     *
     * @param string $level
     * @param string $message
     */
    protected function writeLog($level, string|Stringable $message, array $context): void
    {
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
        Context::override('__logger.context', function ($currentContext) use ($context) {
            return array_merge($currentContext ?: [], $context);
        });

        return $this;
    }

    /**
     * Flush the existing context array.
     *
     * @return $this
     */
    public function withoutContext(): self
    {
        Context::destroy('__logger.context');

        return $this;
    }

    /**
     * Get the existing context array.
     *
     * @return array<string, mixed>
     */
    public function getContext(): array
    {
        return (array) Context::get('__logger.context', []);
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

        if (! method_exists($this->dispatcher, 'listen')) {
            throw new RuntimeException('Events dispatcher does not implement the listen method.');
        }

        /* @phpstan-ignore-next-line */
        $this->dispatcher->listen(MessageLogged::class, $callback);
    }

    /**
     * Fires a log event.
     */
    protected function fireLogEvent(string $level, string $message, array $context = []): void
    {
        // If the event dispatcher is set, we will pass along the parameters to the
        // log listeners. These are useful for building profilers or other tools
        // that aggregate all of the log messages for a given "request" cycle.
        $this->dispatcher?->dispatch(new MessageLogged($level, $message, $context));
    }

    /**
     * Format the parameters for the logger.
     *
     * @param mixed $message
     * @return mixed
     */
    protected function formatMessage($message)
    {
        if (is_array($message)) {
            return var_export($message, true);
        }
        if ($message instanceof Jsonable) {
            return (string) $message;
        }
        if ($message instanceof Arrayable) {
            return var_export($message->toArray(), true);
        }

        return $message;
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
    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->dispatcher;
    }

    /**
     * Set the event dispatcher instance.
     *
     * @return $this
     */
    public function setEventDispatcher(EventDispatcherInterface $dispatcher): self
    {
        $this->dispatcher = $dispatcher;

        return $this;
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
