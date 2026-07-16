<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Closure;
use Hypervel\Context\CoroutineContext;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Contracts\Support\Jsonable;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Log\Events\MessageLogged;
use Hypervel\Support\Traits\Conditionable;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stringable;

class Logger implements LoggerInterface
{
    use Conditionable;

    /**
     * The CoroutineContext key prefix for per-channel logger context.
     */
    protected const CONTEXT_KEY_PREFIX = '__log.logger_state.';

    /**
     * The next worker-unique logger family identifier.
     *
     * This is an identity generator, not resettable state. Resetting it can
     * alias a new logger to context retained under a destroyed logger's key.
     */
    protected static int $nextFamilyId = 0;

    /**
     * The coroutine-local key for this logger channel's context.
     *
     * Named variants share their source channel's slot so context added through
     * either wrapper remains channel-local without leaking to other channels.
     */
    protected readonly string $stateKey;

    /**
     * Create a new log writer instance.
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected ?Dispatcher $dispatcher = null
    ) {
        $this->stateKey = self::CONTEXT_KEY_PREFIX . ++self::$nextFamilyId;

        if ($this->logger instanceof Monolog) {
            // Monolog's fallback detector is instance-global outside Fibers.
            // The wrapper below tracks recursion in coroutine-local state.
            $this->logger->useLoggingLoopDetection(false);
        }
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

        $state = $this->state();
        ++$state->depth;

        try {
            if ($state->depth === 3) {
                $this->logger->warning(
                    'A possible infinite logging loop was detected and aborted. It appears some of your handler code is triggering logging, see the previous log record for a hint as to what may be the cause.'
                );

                return;
            }

            // Depth four is reserved for logging the warning above.
            if ($state->depth >= 5) {
                return;
            }

            $this->logger->{$level}(
                $message = $this->formatMessage($message),
                $context = array_merge($state->context, $context)
            );

            $this->fireLogEvent($level, $message, $context);
        } finally {
            --$state->depth;
        }
    }

    /**
     * Return a named variant of the logger.
     *
     * @throws RuntimeException
     */
    public function withName(string $name): self
    {
        if (! $this->logger instanceof Monolog) {
            throw new RuntimeException('Named loggers are only supported by Monolog drivers.');
        }

        $logger = clone $this;
        $logger->logger = $this->logger->withName($name);

        return $logger;
    }

    /**
     * Add context to all future logs.
     *
     * @return $this
     */
    public function withContext(array $context = []): self
    {
        $state = $this->state();
        $state->context = array_merge($state->context, $context);

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
        $state = $this->state();

        if (is_array($keys)) {
            $state->context = array_diff_key($state->context, array_flip($keys));
        } else {
            $state->context = [];
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
        $state = CoroutineContext::get($this->stateKey);

        return $state instanceof LoggerState ? $state->context : [];
    }

    /**
     * Get the state for this logger family in the current coroutine.
     */
    protected function state(): LoggerState
    {
        return CoroutineContext::getOrSet(
            $this->stateKey,
            static fn () => new LoggerState
        );
    }

    /**
     * Register a new callback handler for when a log event is triggered.
     *
     * Boot-only. Registers a listener on the worker-global event dispatcher;
     * per-request registration persists and affects subsequent requests.
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
            $extra = ContextRepository::hasInstance()
                ? ContextRepository::getInstance()->all()
                : [];

            $this->dispatcher->dispatch(new MessageLogged($level, $message, $context, $extra));
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
     *
     * Boot or tests only. Persists on the cached logger for the worker
     * lifetime; per-request use races across coroutines.
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
