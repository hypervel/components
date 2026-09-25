<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use Closure;
use ErrorException;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Log\LogManager;
use Hypervel\Support\Env;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\ErrorHandler;
use Psr\Log\LoggerInterface;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;
use WeakReference;

class HandleExceptions
{
    /**
     * Reserved memory so that errors can be displayed properly on memory exhaustion.
     */
    public static ?string $reservedMemory = null;

    /**
     * The application instance.
     */
    protected static ?Application $app = null;

    /**
     * The bootstrapper whose handlers are currently installed.
     */
    protected static ?self $owner = null;

    /**
     * The bootstrapper that owned the handlers before this one installed its own.
     */
    protected ?self $previous = null;

    /**
     * The application this bootstrapper installed its handlers for.
     *
     * Registered shutdown callbacks keep their bootstrapper for the process
     * lifetime, so the application is only referenced weakly.
     *
     * @var null|WeakReference<Application>
     */
    protected ?WeakReference $application = null;

    /**
     * The error handler installed by this bootstrapper.
     */
    protected ?Closure $errorHandler = null;

    /**
     * The exception handler installed by this bootstrapper.
     */
    protected ?Closure $exceptionHandler = null;

    /**
     * The display_errors setting this bootstrapper replaced when it installed its handlers.
     */
    protected ?string $displayErrors = null;

    /**
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        static::$reservedMemory = str_repeat('x', 32768);

        // Bootstrapping an application again, as env:encrypt does, keeps the handlers it already installed.
        $installsHandlers = static::$owner === null || static::$app !== $app;

        if ($installsHandlers) {
            $this->previous = static::$owner;
            $this->application = WeakReference::create($app);
            $this->displayErrors = null;

            static::$owner = $this;
            static::$app = $app;

            set_error_handler($this->errorHandler = $this->forwardsTo('handleError'));

            set_exception_handler($this->exceptionHandler = $this->forwardsTo('handleException'));

            register_shutdown_function($this->forwardsTo('handleShutdown'));
        }

        error_reporting(-1);

        if (! $app->environment('testing')) {
            if ($installsHandlers) {
                $this->displayErrors = ini_get('display_errors');
            }

            ini_set('display_errors', 'Off');
        }
    }

    /**
     * Report PHP deprecations, or convert PHP errors to ErrorException instances.
     *
     * @throws ErrorException
     */
    public function handleError(int $level, string $message, string $file = '', int $line = 0): void
    {
        if ($this->isDeprecation($level)) {
            $this->handleDeprecationError($message, $file, $line, $level);
        } elseif (error_reporting() & $level) {
            throw new ErrorException($message, 0, $level, $file, $line);
        }
    }

    /**
     * Report a deprecation to the "deprecations" logger.
     */
    public function handleDeprecationError(string $message, string $file, int $line, int $level = E_DEPRECATED): void
    {
        if ($this->shouldIgnoreDeprecationErrors()) {
            return;
        }

        if (! static::$app->bound('config')) {
            return;
        }

        try {
            $logger = static::$app->make(LogManager::class);
        } catch (Throwable $exception) {
            // Cancellation is injected asynchronously, so a dedicated catch analyzes as unreachable here.
            if ($exception instanceof CanceledException) {
                throw $exception;
            }

            return;
        }

        // Invalid configuration must remain visible even when reporting failures are ignored.
        $this->ensureDeprecationLoggerIsConfigured();

        $trace = static::$app->make('config')->boolean('logging.deprecations.trace', false);

        try {
            with($logger->channel('deprecations'), function (LoggerInterface $log) use ($message, $file, $line, $level, $trace): void {
                if ($trace) {
                    $log->warning($message, [
                        'exception' => new ErrorException($message, 0, $level, $file, $line),
                    ]);
                } else {
                    $log->warning(sprintf(
                        '%s in %s on line %s',
                        $message,
                        $file,
                        $line
                    ));
                }
            });
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable) {
            return;
        }
    }

    /**
     * Determine if deprecation errors should be ignored.
     */
    protected function shouldIgnoreDeprecationErrors(): bool
    {
        return ! class_exists(LogManager::class)
            || static::$app === null
            || ! static::$app->hasBeenBootstrapped()
            || (static::$app->runningUnitTests() && ! Env::get('LOG_DEPRECATIONS_WHILE_TESTING'));
    }

    /**
     * Ensure the "deprecations" logger is configured.
     */
    protected function ensureDeprecationLoggerIsConfigured(): void
    {
        $config = static::$app->make('config');

        if ($config->get('logging.channels.deprecations')) {
            return;
        }

        $options = $config->array('logging.deprecations');

        $this->ensureNullLogDriverIsConfigured();

        // A declared null channel deliberately selects the null logger.
        $driver = $options['channel'] ?? 'null';

        $config->set('logging.channels.deprecations', $config->array("logging.channels.{$driver}"));
    }

    /**
     * Ensure the "null" log driver is configured.
     */
    protected function ensureNullLogDriverIsConfigured(): void
    {
        $config = static::$app->make('config');

        if ($config->get('logging.channels.null')) {
            return;
        }

        $config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ]);
    }

    /**
     * Handle an uncaught exception from the application.
     *
     * Note: Most exceptions can be handled via the try / catch block in
     * the HTTP and Console kernels. But, fatal error exceptions must
     * be handled differently since they are not normal exceptions.
     */
    public function handleException(Throwable $e): void
    {
        static::$reservedMemory = null;

        try {
            $this->getExceptionHandler()->report($e);
        } catch (Throwable) {
            $exceptionHandlerFailed = true;

            try {
                error_log((string) $e);
            } catch (Throwable) {
            }
        }

        // Swoole callbacks own response emission; this global backstop has no native response.
        if (! static::$app->runningInConsole()) {
            return;
        }

        $this->renderForConsole($e);

        if ($exceptionHandlerFailed ?? false) {
            exit(1);
        }
    }

    /**
     * Render an exception to the console.
     */
    protected function renderForConsole(Throwable $e): void
    {
        $this->getExceptionHandler()->renderForConsole((new ConsoleOutput)->getErrorOutput(), $e);
    }

    /**
     * Handle the PHP shutdown event.
     */
    public function handleShutdown(): void
    {
        static::$reservedMemory = null;

        if (! is_null($error = error_get_last()) && $this->isFatal($error['type'])) {
            $this->handleException($this->fatalErrorFromPhpError($error, 0));
        }
    }

    /**
     * Create a new fatal error instance from an error array.
     */
    protected function fatalErrorFromPhpError(array $error, ?int $traceOffset = null): FatalError
    {
        return new FatalError($error['message'], 0, $error, $traceOffset);
    }

    /**
     * Forward a method call to the bootstrapper that currently owns the handlers.
     *
     * A released error or exception handler can return to the top of PHP's stack
     * when a handler installed after it is removed, so it forwards to the current
     * owner. Shutdown callbacks only run for their own owner, since the owner's
     * callback is registered too. Without an owner, errors fall back to PHP.
     */
    protected function forwardsTo(string $method): Closure
    {
        return function (mixed ...$arguments) use ($method): mixed {
            $owner = static::$owner;

            if ($owner !== null && static::$app !== null && ($owner === $this || $method !== 'handleShutdown')) {
                return $owner->{$method}(...$arguments);
            }

            // An exception handler that returns false marks the exception handled and hides it.
            if ($method === 'handleException') {
                throw $arguments[0];
            }

            return false;
        };
    }

    /**
     * Determine if the error level is a deprecation.
     */
    protected function isDeprecation(int $level): bool
    {
        return in_array($level, [E_DEPRECATED, E_USER_DEPRECATED]);
    }

    /**
     * Determine if the error type is fatal.
     */
    protected function isFatal(int $type): bool
    {
        return in_array($type, [E_COMPILE_ERROR, E_CORE_ERROR, E_ERROR, E_PARSE]);
    }

    /**
     * Get an instance of the exception handler.
     */
    protected function getExceptionHandler(): ExceptionHandler
    {
        return static::$app->make(ExceptionHandler::class);
    }

    /**
     * Release the handlers installed for an application that is being discarded.
     *
     * Boot or tests only. Restores the handlers, display_errors setting and
     * application that were active before the application was bootstrapped, so
     * later errors are reported without its flushed container. Handlers and
     * settings changed by other code stay in place.
     */
    public static function release(Application $app): void
    {
        $owner = static::$owner;

        if ($owner === null || static::$app !== $app) {
            return;
        }

        if (get_exception_handler() === $owner->exceptionHandler) {
            restore_exception_handler();
        }

        if (get_error_handler() === $owner->errorHandler) {
            restore_error_handler();
        }

        if ($owner->displayErrors !== null && ini_get('display_errors') === 'Off') {
            ini_set('display_errors', $owner->displayErrors);
        }

        static::$owner = $owner->previous;
        static::$app = $owner->previous?->application?->get();
    }

    // Laravel's deprecated forgetApp() is omitted; use flushState() for test cleanup.

    /**
     * Flush all static state.
     */
    public static function flushState(?TestCase $testCase = null): void
    {
        static::$owner = null;

        // AfterEachTestSubscriber resets framework static state after each test.
        // This reset remains caller-driven because restoring PHPUnit's error
        // handler requires the active test case.
        if (is_null(static::$app)) {
            return;
        }

        static::flushHandlersState($testCase);

        static::$app = null;

        static::$reservedMemory = null;
    }

    /**
     * Flush the bootstrapper's global handlers state.
     */
    public static function flushHandlersState(?TestCase $testCase = null): void
    {
        while (get_exception_handler() !== null) {
            restore_exception_handler();
        }

        while (get_error_handler() !== null) {
            restore_error_handler();
        }

        if (class_exists(ErrorHandler::class)) {
            $instance = ErrorHandler::instance();

            if ((fn () => $this->enabled ?? false)->call($instance)) { // @phpstan-ignore nullCoalesce.property (Closure::call() rebinds $this to ErrorHandler; PHPStan cannot model this)
                $instance->disable();
                $instance->enable($testCase);
            }
        }
    }
}
