<?php

declare(strict_types=1);

namespace Hypervel\Log;

use Closure;
use Hyperf\Collection\Collection;
use Hyperf\Context\Context;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Stringable\Str;
use Hypervel\Support\Environment;
use InvalidArgumentException;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\ErrorLogHandler;
use Monolog\Handler\FingersCrossedHandler;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\SlackWebhookHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogHandler;
use Monolog\Handler\WhatFailureGroupHandler;
use Monolog\Logger as Monolog;
use Monolog\Processor\ProcessorInterface;
use Monolog\Processor\PsrLogMessageProcessor;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Stringable;
use Throwable;

/**
 * @mixin \Hypervel\Log\Logger
 */
class LogManager implements LoggerInterface
{
    use ParsesLogConfiguration;

    /**
     * The config for log.
     */
    protected ConfigInterface $config;

    /**
     * The array of resolved channels.
     */
    protected array $channels = [];

    /**
     * The registered custom driver creators.
     */
    protected array $customCreators = [];

    /**
     * The standard date format to use when writing logs.
     */
    protected string $dateFormat = 'Y-m-d H:i:s';

    /**
     * Create a new Log manager instance.
     */
    public function __construct(
        protected ContainerInterface $app
    ) {
        $this->config = $this->app->get(ConfigInterface::class);
    }

    /**
     * Build an on-demand log channel.
     */
    public function build(array $config): LoggerInterface
    {
        unset($this->channels['ondemand']);

        return $this->get('ondemand', $config);
    }

    /**
     * Create a new, on-demand aggregate logger instance.
     */
    public function stack(array $channels, ?string $channel = null): LoggerInterface
    {
        return new Logger(
            $this->createStackDriver(compact('channels', 'channel')),
            $this->app->get(EventDispatcherInterface::class)
        );
    }

    /**
     * Get a log channel instance.
     */
    public function channel(?string $channel = null): LoggerInterface
    {
        return $this->driver($channel);
    }

    /**
     * Get a log driver instance.
     */
    public function driver(?string $driver = null): LoggerInterface
    {
        return $this->get($this->parseDriver($driver));
    }

    /**
     * Attempt to get the log from the local cache.
     */
    protected function get(?string $name, ?array $config = null): LoggerInterface
    {
        try {
            return $this->channels[$name] ?? with($this->resolve($name, $config), function ($logger) use ($name) {
                return $this->channels[$name] = $this->tap($name, new Logger($logger, $this->app->get(EventDispatcherInterface::class)));
            });
        } catch (Throwable $e) {
            return tap($this->createEmergencyLogger(), function ($logger) use ($e) {
                $logger->emergency('Unable to create configured logger. Using emergency logger.', [
                    'exception' => $e,
                ]);
            });
        }
    }

    /**
     * Apply the configured taps for the logger.
     */
    protected function tap(string $name, Logger $logger): Logger
    {
        foreach ($this->configurationFor($name)['tap'] ?? [] as $tap) {
            [$class, $arguments] = $this->parseTap($tap);

            $this->app->make($class)->__invoke($logger, ...explode(',', $arguments));
        }

        return $logger;
    }

    /**
     * Parse the given tap class string into a class name and arguments string.
     */
    protected function parseTap(string $tap): array
    {
        return str_contains($tap, ':') ? explode(':', $tap, 2) : [$tap, ''];
    }

    /**
     * Create an emergency log handler to avoid white screens of death.
     */
    protected function createEmergencyLogger(): LoggerInterface
    {
        $config = $this->configurationFor('emergency');

        $handler = new StreamHandler(
            $config['path'] ?? 'php://stdout',
            $this->level(['level' => 'debug'])
        );

        return new Logger(
            new Monolog('hypervel', $this->prepareHandlers([$handler])),
            $this->app->get(EventDispatcherInterface::class)
        );
    }

    /**
     * Resolve the given log instance by name.
     *
     * @throws InvalidArgumentException
     */
    protected function resolve(?string $name, ?array $config = null): LoggerInterface
    {
        $config ??= $this->configurationFor($name);

        if (is_null($config)) {
            throw new InvalidArgumentException("Log [{$name}] is not defined.");
        }

        if (isset($this->customCreators[$config['driver']])) {
            return $this->callCustomCreator($config);
        }

        $driverMethod = 'create' . ucfirst($config['driver']) . 'Driver';

        if (method_exists($this, $driverMethod)) {
            return $this->{$driverMethod}($config);
        }

        throw new InvalidArgumentException("Driver [{$config['driver']}] is not supported.");
    }

    /**
     * Call a custom driver creator.
     */
    protected function callCustomCreator(array $config): mixed
    {
        return $this->customCreators[$config['driver']]($this->app, $config);
    }

    /**
     * Create a custom log driver instance.
     */
    protected function createCustomDriver(array $config): LoggerInterface
    {
        $factory = is_callable($via = $config['via']) ? $via : $this->app->make($via);

        return $factory($config);
    }

    /**
     * Create an aggregate log driver instance.
     */
    protected function createStackDriver(array $config): LoggerInterface
    {
        if (is_string($config['channels'])) {
            $config['channels'] = explode(',', $config['channels']);
        }

        $handlers = Collection::make($config['channels'])->flatMap(function ($channel) {
            return $channel instanceof LoggerInterface
                ? $channel->getHandlers() // @phpstan-ignore-line
                : $this->channel($channel)->getHandlers(); // @phpstan-ignore-line
        })->all();

        $processors = Collection::make($config['channels'])->flatMap(function ($channel) {
            return $channel instanceof LoggerInterface
                ? $channel->getProcessors() // @phpstan-ignore-line
                : $this->channel($channel)->getProcessors(); // @phpstan-ignore-line
        })->all();

        if ($config['ignore_exceptions'] ?? false) {
            $handlers = [new WhatFailureGroupHandler($handlers)];
        }

        return new Monolog($this->parseChannel($config), $handlers, $processors);
    }

    /**
     * Create an instance of the single file log driver.
     */
    protected function createSingleDriver(array $config): LoggerInterface
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler(
                new StreamHandler(
                    $config['path'],
                    $this->level($config),
                    $config['bubble'] ?? true,
                    $config['permission'] ?? null,
                    $config['locking'] ?? false
                ),
                $config
            ),
        ], $config['replace_placeholders'] ?? false ? [new PsrLogMessageProcessor()] : []);
    }

    /**
     * Create an instance of the daily file log driver.
     */
    protected function createDailyDriver(array $config): LoggerInterface
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler(new RotatingFileHandler(
                $config['path'],
                $config['days'] ?? 7,
                $this->level($config),
                $config['bubble'] ?? true,
                $config['permission'] ?? null,
                $config['locking'] ?? false
            ), $config),
        ], $config['replace_placeholders'] ?? false ? [new PsrLogMessageProcessor()] : []);
    }

    /**
     * Create an instance of the Slack log driver.
     */
    protected function createSlackDriver(array $config): LoggerInterface
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler(new SlackWebhookHandler(
                $config['url'],
                $config['channel'] ?? null,
                $config['username'] ?? 'Hyperf',
                $config['attachment'] ?? true,
                $config['emoji'] ?? ':boom:',
                $config['short'] ?? false,
                $config['context'] ?? true,
                $this->level($config),
                $config['bubble'] ?? true,
                $config['exclude_fields'] ?? []
            ), $config),
        ], $config['replace_placeholders'] ?? false ? [new PsrLogMessageProcessor()] : []);
    }

    /**
     * Create an instance of the syslog log driver.
     */
    protected function createSyslogDriver(array $config): LoggerInterface
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler(new SyslogHandler(
                Str::snake($this->config->get('app.name', 'hyperf'), '-'),
                $config['facility'] ?? LOG_USER,
                $this->level($config)
            ), $config),
        ], $config['replace_placeholders'] ?? false ? [new PsrLogMessageProcessor()] : []);
    }

    /**
     * Create an instance of the "error log" log driver.
     */
    protected function createErrorlogDriver(array $config): LoggerInterface
    {
        return new Monolog($this->parseChannel($config), [
            $this->prepareHandler(new ErrorLogHandler(
                $config['type'] ?? ErrorLogHandler::OPERATING_SYSTEM,
                $this->level($config)
            )),
        ], $config['replace_placeholders'] ?? false ? [new PsrLogMessageProcessor()] : []);
    }

    /**
     * Create an instance of any handler available in Monolog.
     *
     * @throws InvalidArgumentException
     */
    protected function createMonologDriver(array $config): LoggerInterface
    {
        if (! is_a($config['handler'], HandlerInterface::class, true)) {
            throw new InvalidArgumentException(
                $config['handler'] . ' must be an instance of ' . HandlerInterface::class
            );
        }

        Collection::make($config['processors'] ?? [])->each(function ($processor) {
            $processor = $processor['processor'] ?? $processor;

            if (! is_a($processor, ProcessorInterface::class, true)) {
                throw new InvalidArgumentException(
                    $processor . ' must be an instance of ' . ProcessorInterface::class
                );
            }
        });

        $with = array_merge(
            ['level' => $this->level($config)],
            $config['with'] ?? [],
            $config['handler_with'] ?? []
        );

        $handler = $this->prepareHandler(
            $this->app->make($config['handler'], $with),
            $config
        );

        $processors = Collection::make($config['processors'] ?? [])
            ->map(fn ($processor) => $this->app->make($processor['processor'] ?? $processor, $processor['with'] ?? []))
            ->toArray();

        return new Monolog(
            $this->parseChannel($config),
            [$handler],
            $processors,
        );
    }

    /**
     * Prepare the handlers for usage by Monolog.
     */
    protected function prepareHandlers(array $handlers): array
    {
        foreach ($handlers as $key => $handler) {
            $handlers[$key] = $this->prepareHandler($handler);
        }

        return $handlers;
    }

    /**
     * Prepare the handler for usage by Monolog.
     */
    protected function prepareHandler(HandlerInterface $handler, array $config = []): HandlerInterface
    {
        if (isset($config['action_level'])) {
            $handler = new FingersCrossedHandler(
                $handler,
                $this->actionLevel($config),
                0,
                true,
                $config['stop_buffering'] ?? true
            );
        }

        if (! $handler instanceof FormattableHandlerInterface) {
            return $handler;
        }

        if (! isset($config['formatter'])) {
            $handler->setFormatter($this->formatter());
        } elseif ($config['formatter'] !== 'default') {
            $handler->setFormatter($this->app->make($config['formatter'], $config['formatter_with'] ?? []));
        }

        return $handler;
    }

    /**
     * Get a Monolog formatter instance.
     */
    protected function formatter(): \Monolog\Formatter\FormatterInterface
    {
        return new LineFormatter(null, $this->dateFormat, true, true, true);
    }

    /**
     * Share context across channels and stacks.
     *
     * @return $this
     */
    public function shareContext(array $context): self
    {
        foreach ($this->channels as $channel) {
            $channel->withContext($context);
        }

        Context::override('__logger.shared_context', function ($currentContext) use ($context) {
            return array_merge($currentContext ?: [], $context);
        });

        return $this;
    }

    /**
     * The context shared across channels and stacks.
     */
    public function sharedContext(): array
    {
        return (array) Context::get('__logger.shared_context', []);
    }

    /**
     * Flush the log context on all currently resolved channels.
     *
     * @return $this
     */
    public function withoutContext(): self
    {
        foreach ($this->channels as $channel) {
            if (method_exists($channel, 'withoutContext')) {
                $channel->withoutContext();
            }
        }

        return $this;
    }

    /**
     * Flush the shared context.
     *
     * @return $this
     */
    public function flushSharedContext(): self
    {
        Context::destroy('__logger.shared_context');

        return $this;
    }

    /**
     * Get fallback log channel name.
     */
    protected function getFallbackChannelName(): string
    {
        return $this->app->get(Environment::class)->get() ?: 'production';
    }

    /**
     * Get the log connection configuration.
     */
    protected function configurationFor(string $name): ?array
    {
        return $this->config->get("logging.channels.{$name}");
    }

    /**
     * Get the default log driver name.
     */
    public function getDefaultDriver(): ?string
    {
        return $this->config->get('logging.default');
    }

    /**
     * Set the default log driver name.
     */
    public function setDefaultDriver(string $name): void
    {
        $this->config->set('logging.default', $name);
    }

    /**
     * Register a custom driver creator Closure.
     *
     * @return $this
     */
    public function extend(string $driver, Closure $callback): static
    {
        $this->customCreators[$driver] = $callback->bindTo($this, $this);

        return $this;
    }

    /**
     * Unset the given channel instance.
     */
    public function forgetChannel(?string $driver = null): void
    {
        $driver = $this->parseDriver($driver);

        if (isset($this->channels[$driver])) {
            unset($this->channels[$driver]);
        }
    }

    /**
     * Parse the driver name.
     */
    protected function parseDriver(?string $driver): ?string
    {
        $driver ??= $this->getDefaultDriver();

        if ($this->app->get(Environment::class)->isTesting()) {
            $driver ??= 'null';
        }

        return $driver;
    }

    /**
     * Get all of the resolved log channels.
     */
    public function getChannels(): array
    {
        return $this->channels;
    }

    /**
     * System is unusable.
     *
     * @param string $message
     */
    public function emergency(string|Stringable $message, array $context = []): void
    {
        $this->driver()->emergency($message, $context);
    }

    /**
     * Action must be taken immediately.
     *
     * Example: Entire website down, database unavailable, etc. This should
     * trigger the SMS alerts and wake you up.
     *
     * @param string $message
     */
    public function alert(string|Stringable $message, array $context = []): void
    {
        $this->driver()->alert($message, $context);
    }

    /**
     * Critical conditions.
     *
     * Example: Application component unavailable, unexpected exception.
     *
     * @param string $message
     */
    public function critical(string|Stringable $message, array $context = []): void
    {
        $this->driver()->critical($message, $context);
    }

    /**
     * Runtime errors that do not require immediate action but should typically
     * be logged and monitored.
     *
     * @param string $message
     */
    public function error(string|Stringable $message, array $context = []): void
    {
        $this->driver()->error($message, $context);
    }

    /**
     * Exceptional occurrences that are not errors.
     *
     * Example: Use of deprecated APIs, poor use of an API, undesirable things
     * that are not necessarily wrong.
     *
     * @param string $message
     */
    public function warning(string|Stringable $message, array $context = []): void
    {
        $this->driver()->warning($message, $context);
    }

    /**
     * Normal but significant events.
     *
     * @param string $message
     */
    public function notice(string|Stringable $message, array $context = []): void
    {
        $this->driver()->notice($message, $context);
    }

    /**
     * Interesting events.
     *
     * Example: User logs in, SQL logs.
     *
     * @param string $message
     */
    public function info(string|Stringable $message, array $context = []): void
    {
        $this->driver()->info($message, $context);
    }

    /**
     * Detailed debug information.
     *
     * @param string $message
     */
    public function debug(string|Stringable $message, array $context = []): void
    {
        $this->driver()->debug($message, $context);
    }

    /**
     * Logs with an arbitrary level.
     *
     * @param mixed $level
     * @param string $message
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->driver()->log($level, $message, $context);
    }

    /**
     * Dynamically call the default driver instance.
     *
     * @param string $method
     * @param array $parameters
     * @return mixed
     */
    public function __call($method, $parameters)
    {
        return $this->driver()->{$method}(...$parameters);
    }
}
