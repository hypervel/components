<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Bootstrap;

use ErrorException;
use Exception;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Log\LogManager;
use Hypervel\Support\Env;
use PHPUnit\Framework\TestCase;
use PHPUnit\Runner\ErrorHandler;
use PHPUnit\Runner\Version;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\ErrorHandler\Error\FatalError;
use Throwable;

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
     * Bootstrap the given application.
     */
    public function bootstrap(Application $app): void
    {
        static::$reservedMemory = str_repeat('x', 32768);

        static::$app = $app;

        error_reporting(-1);

        set_error_handler($this->forwardsTo('handleError'));

        set_exception_handler($this->forwardsTo('handleException'));

        register_shutdown_function($this->forwardsTo('handleShutdown'));

        if (! $app->environment('testing')) {
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

        try {
            $logger = static::$app->make(LogManager::class);
        } catch (Exception) {
            return;
        }

        $options = static::$app['config']->get('logging.deprecations') ?? [];
        $channel = $options['channel'] ?? 'null';

        with($logger->channel($channel), function ($log) use ($message, $file, $line, $level, $options) {
            if ($options['trace'] ?? false) {
                $log->warning((string) new ErrorException($message, 0, $level, $file, $line));
            } else {
                $log->warning(sprintf(
                    '%s in %s on line %s',
                    $message,
                    $file,
                    $line
                ));
            }
        });
    }

    /**
     * Determine if deprecation errors should be ignored.
     */
    protected function shouldIgnoreDeprecationErrors(): bool
    {
        return ! class_exists(LogManager::class)
            || ! static::$app->hasBeenBootstrapped()
            || (static::$app->runningUnitTests() && ! Env::get('LOG_DEPRECATIONS_WHILE_TESTING'));
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
        } catch (Exception) {
            $exceptionHandlerFailed = true;
        }

        if (static::$app->runningInConsole()) {
            $this->renderForConsole($e);

            if ($exceptionHandlerFailed ?? false) {
                exit(1);
            }
        } else {
            $this->renderHttpResponse($e);
        }
    }

    /**
     * Render an exception to the console.
     */
    protected function renderForConsole(Throwable $e): void
    {
        $this->getExceptionHandler()->renderForConsole(new ConsoleOutput(), $e);
    }

    /**
     * Render an exception as an HTTP response and send it.
     */
    protected function renderHttpResponse(Throwable $e): void
    {
        $this->getExceptionHandler()->render(static::$app['request'], $e)->send();
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
     * Forward a method call to the given method if an application instance exists.
     */
    protected function forwardsTo(string $method): callable
    {
        return fn (...$arguments) => static::$app
            ? $this->{$method}(...$arguments)
            : false;
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
     * Clear the local application instance from memory.
     *
     * @deprecated this method will be removed in a future Laravel version
     */
    public static function forgetApp(): void
    {
        static::$app = null;
    }

    /**
     * Flush the bootstrapper's global state.
     */
    public static function flushState(?TestCase $testCase = null): void
    {
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

            if ((fn () => $this->enabled ?? false)->call($instance)) { // @phpstan-ignore if.alwaysFalse (Closure::call() rebinds $this to ErrorHandler; phpstan can't model this)
                $instance->disable();

                // @TODO Remove the version check once PHPUnit minimum is bumped to 13.
                // PHPUnit 12.3.4+ requires the TestCase argument; older versions don't accept it.
                if (version_compare(Version::id(), '12.3.4', '>=')) {
                    $instance->enable($testCase); // @phpstan-ignore arguments.count (PHPUnit 12.3.4+ signature; guarded by version_compare above)
                } else {
                    $instance->enable();
                }
            }
        }
    }
}
