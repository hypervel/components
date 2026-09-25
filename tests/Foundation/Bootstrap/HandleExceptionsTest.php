<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Bootstrap;

use Error;
use ErrorException;
use Hypervel\Config\Repository as Config;
use Hypervel\Contracts\Debug\ExceptionHandler;
use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Bootstrap\HandleExceptions;
use Hypervel\Log\LogManager;
use Hypervel\Support\Env;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Monolog\Handler\NullHandler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Symfony\Component\Console\Output\StreamOutput;

class HandleExceptionsTest extends TestCase
{
    protected $app;

    protected Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = m::mock(Application::setInstance(new Application));

        $this->app->instance('config', $this->config = new Config([
            'logging' => [
                'deprecations' => [
                    'channel' => 'null',
                    'trace' => false,
                ],
                'channels' => [
                    'null' => [
                        'driver' => 'monolog',
                        'handler' => NullHandler::class,
                    ],
                ],
            ],
        ]));
    }

    protected function handleExceptions(): HandleExceptions
    {
        return tap(new HandleExceptions, function ($instance) {
            (new ReflectionClass($instance))->getProperty('app')->setValue($instance, $this->app);
        });
    }

    protected function tearDown(): void
    {
        Application::setInstance(null);
        HandleExceptions::flushState($this);

        parent::tearDown();
    }

    public function testPhpDeprecations()
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning')->with(sprintf(
            '%s in %s on line %s',
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        ));

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );
    }

    public function testDeprecationLoggingPreservesCancellationDuringLoggerResolution(): void
    {
        $cancellation = new CanceledException('canceled');
        $this->app->bind(LogManager::class, fn () => throw $cancellation);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        try {
            $this->handleExceptions()->handleDeprecationError(
                'Deprecated behavior',
                __FILE__,
                __LINE__,
            );

            $this->fail('The cancellation was not preserved.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testPhpDeprecationsWithStackTraces(): void
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $this->config->set('logging.deprecations', [
            'channel' => 'null',
            'trace' => true,
        ]);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning')->with(
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            m::on(function (array $context): bool {
                $exception = $context['exception'] ?? null;

                return $exception instanceof ErrorException
                    && $exception->getSeverity() === E_DEPRECATED
                    && $exception->getFile() === '/home/user/hypervel/routes/web.php'
                    && $exception->getLine() === 17
                    && $exception->getTrace() !== [];
            })
        );

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );
    }

    public function testEnsuresDeprecationsDriver()
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $this->config->set('logging.channels.stack', [
            'driver' => 'stack',
            'channels' => ['single'],
            'ignore_exceptions' => false,
        ]);
        $this->config->set('logging.deprecations', [
            'channel' => 'stack',
            'trace' => false,
        ]);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning')->with(sprintf(
            '%s in %s on line %s',
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        ));

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );

        $this->assertSame(
            [
                'driver' => 'stack',
                'channels' => ['single'],
                'ignore_exceptions' => false,
            ],
            $this->config->get('logging.channels.deprecations')
        );
    }

    public function testNullValueAsChannelUsesNullDriver()
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $this->config->set('logging.deprecations', [
            'channel' => null,
            'trace' => false,
        ]);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning')->with(sprintf(
            '%s in %s on line %s',
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        ));

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );

        $this->assertSame(
            [
                'driver' => 'monolog',
                'handler' => NullHandler::class,
            ],
            $this->config->get('logging.channels.deprecations')
        );
    }

    public function testMissingDeprecationOptionsUseTheNullChannelWithoutATrace(): void
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);
        $this->config->set('logging.deprecations', []);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning')->with(sprintf(
            '%s in %s on line %s',
            'Deprecated behavior',
            __FILE__,
            42,
        ));

        $this->handleExceptions()->handleDeprecationError(
            'Deprecated behavior',
            __FILE__,
            42,
        );

        $this->assertSame(
            [
                'driver' => 'monolog',
                'handler' => NullHandler::class,
            ],
            $this->config->get('logging.channels.deprecations'),
        );
    }

    #[DataProvider('invalidDeprecationConfigurationProvider')]
    public function testInvalidDeprecationConfigurationFailsLoudly(mixed $configuration, string $key): void
    {
        $this->app->instance(LogManager::class, m::mock(LogManager::class));
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);
        $this->config->set('logging.deprecations', $configuration);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains($key);

        $this->handleExceptions()->handleDeprecationError(
            'Deprecated behavior',
            __FILE__,
            __LINE__,
        );
    }

    /**
     * Provide unsupported deprecation configuration shapes.
     */
    public static function invalidDeprecationConfigurationProvider(): array
    {
        return [
            'legacy scalar' => ['null', 'logging.deprecations'],
            'unknown channel' => [['channel' => 'missing', 'trace' => false], 'logging.channels.missing'],
        ];
    }

    public function testUserDeprecations()
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning')->with(sprintf(
            '%s in %s on line %s',
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        ));

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );
    }

    public function testUserDeprecationsWithStackTraces(): void
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $this->config->set('logging.deprecations', [
            'channel' => 'null',
            'trace' => true,
        ]);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning')->with(
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            m::on(function (array $context): bool {
                $exception = $context['exception'] ?? null;

                return $exception instanceof ErrorException
                    && $exception->getSeverity() === E_USER_DEPRECATED
                    && $exception->getFile() === '/home/user/hypervel/routes/web.php'
                    && $exception->getLine() === 17
                    && $exception->getTrace() !== [];
            })
        );

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );
    }

    public function testEnsuresNullDeprecationsDriver()
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning');

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );

        $this->assertSame(
            NullHandler::class,
            $this->config->get('logging.channels.deprecations.handler')
        );
    }

    public function testEnsuresNullLogDriver()
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning');
        $this->config->set('logging.channels.null', null);

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );

        $this->assertSame(
            NullHandler::class,
            $this->config->get('logging.channels.deprecations.handler')
        );
    }

    public function testDoNotOverrideExistingNullLogDriver()
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning');

        $this->config->set('logging.channels.null', [
            'driver' => 'monolog',
            'handler' => CustomNullHandler::class,
        ]);

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );

        $this->assertSame(
            CustomNullHandler::class,
            $this->config->get('logging.channels.deprecations.handler')
        );
    }

    public function testNoDeprecationsDriverIfNoDeprecationsHereSend(): void
    {
        $this->assertNull($this->config->get('logging.channels.deprecations'));
    }

    public function testErrors(): void
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);

        $logger->shouldNotReceive('channel');
        $logger->shouldNotReceive('warning');

        $this->expectExceptionObject(new ErrorException('Something went wrong'));

        $this->handleExceptions()->handleError(
            E_ERROR,
            'Something went wrong',
            '/home/user/hypervel/src/Providers/AppServiceProvider.php',
            17
        );
    }

    public function testConsoleThrowableRendersToStandardError(): void
    {
        $exception = new RuntimeException('Test exception');

        $handler = m::mock(ExceptionHandler::class);
        $handler->shouldReceive('renderForConsole')->once()->with(
            m::on(function (mixed $output): bool {
                if (! $output instanceof StreamOutput) {
                    return false;
                }

                return stream_get_meta_data($output->getStream())['uri'] === 'php://stderr';
            }),
            $exception,
        );
        $this->app->instance(ExceptionHandler::class, $handler);

        $method = new ReflectionMethod($handleExceptions = $this->handleExceptions(), 'renderForConsole');
        $method->invoke($handleExceptions, $exception);
    }

    public function testNonConsoleExceptionIsReportedWithoutRenderingAResponse(): void
    {
        $exception = new RuntimeException('uncaught coroutine failure');
        $handler = m::mock(ExceptionHandler::class);
        $handler->expects('report')->with($exception);
        $handler->shouldNotReceive('render');
        $handler->shouldNotReceive('renderForConsole');
        $this->app->expects('make')->with(ExceptionHandler::class)->andReturn($handler);
        $this->app->expects('runningInConsole')->andReturnFalse();

        $this->handleExceptions()->handleException($exception);
    }

    public function testNonConsoleReporterFailureFallsBackToThePhpErrorLog(): void
    {
        $directory = ParallelTesting::tempDir('HandleExceptionsTest');
        (new Filesystem)->deleteDirectory($directory);
        mkdir($directory, 0777, true);
        $errorLog = $directory . '/php-error.log';
        $previousErrorLog = ini_set('error_log', $errorLog);
        $exception = new RuntimeException('uncaught coroutine failure');
        $handler = m::mock(ExceptionHandler::class);
        $handler->expects('report')->with($exception)->andThrow(new Error('reporting failed'));
        $handler->shouldNotReceive('render');
        $handler->shouldNotReceive('renderForConsole');
        $this->app->expects('make')->with(ExceptionHandler::class)->andReturn($handler);
        $this->app->expects('runningInConsole')->andReturnFalse();

        try {
            $this->handleExceptions()->handleException($exception);
            $contents = file_get_contents($errorLog);

            $this->assertIsString($contents);
            $this->assertStringContainsString('uncaught coroutine failure', $contents);
            $this->assertStringNotContainsString('reporting failed', $contents);
        } finally {
            if ($previousErrorLog !== false) {
                ini_set('error_log', $previousErrorLog);
            }

            (new Filesystem)->deleteDirectory($directory);
        }
    }

    public function testDeprecationErrorsAreIgnoredWhenAppIsNull(): void
    {
        HandleExceptions::flushState($this);

        $handleExceptions = new HandleExceptions;
        $method = new ReflectionMethod($handleExceptions, 'shouldIgnoreDeprecationErrors');

        $this->assertTrue($method->invoke($handleExceptions));

        $handleExceptions->handleDeprecationError(
            'Deprecated behavior',
            __FILE__,
            __LINE__,
        );
    }

    public function testIgnoresDeprecationsUntilConfigurationIsBound(): void
    {
        $this->app = m::mock(Application::class);
        $this->app->expects('hasBeenBootstrapped')->andReturnTrue();
        $this->app->expects('runningUnitTests')->andReturnFalse();
        $this->app->expects('bound')->with('config')->andReturnFalse();
        $this->app->shouldNotReceive('make');

        $this->handleExceptions()->handleDeprecationError(
            'Deprecated behavior',
            __FILE__,
            __LINE__,
        );
    }

    #[TestWith([RuntimeException::class])]
    #[TestWith([Error::class])]
    public function testIgnoreDeprecationIfLoggerUnresolvable(string $exceptionClass): void
    {
        $this->app->bind(LogManager::class, static fn (): never => throw new $exceptionClass);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );
    }

    public function testIgnoreDeprecationIfLoggingFails(): void
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $logger->expects('channel')->with('deprecations')->andThrow(new Error('Class "Monolog\Logger" not found'));

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );
    }

    public function testDeprecationLoggingPreservesCancellationDuringChannelCreation(): void
    {
        $cancellation = new CanceledException('canceled');
        $logger = new LogManager($this->app);
        $logger->extend('canceling', static fn (): never => throw $cancellation);
        $this->config->set('logging.channels.deprecations', ['driver' => 'canceling']);
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->twice()->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        try {
            $this->handleExceptions()->handleDeprecationError('Deprecated behavior', __FILE__, __LINE__);

            $this->fail('The cancellation was not preserved.');
        } catch (CanceledException $exception) {
            $this->assertSame($cancellation, $exception);
        }
    }

    public function testItIgnoreDeprecationLoggingWhenRunningUnitTests()
    {
        $resolved = false;
        $this->app->bind(LogManager::class, function () use (&$resolved) {
            $resolved = true;

            throw new RuntimeException;
        });
        $this->app->expects('runningUnitTests')->andReturn(true);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );

        $this->assertFalse($resolved);
    }

    public function testItCanForceViaConfigDeprecationLoggingWhenRunningUnitTests()
    {
        $logger = m::mock(LogManager::class);
        $logger->expects('channel')->with('deprecations')->andReturnSelf();
        $logger->expects('warning');
        $this->app->instance(LogManager::class, $logger);
        $this->app->expects('runningUnitTests')->andReturn(true);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        Env::getRepository()->set('LOG_DEPRECATIONS_WHILE_TESTING', 'true');

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/hypervel/routes/web.php',
            17
        );

        Env::getRepository()->clear('LOG_DEPRECATIONS_WHILE_TESTING');
    }

    // REMOVED: forgetApp() is deprecated; flushState() owns application cleanup.
    public function testHandlerForgetsPreviousApp()
    {
        $instance = $this->handleExceptions();

        $appResolver = fn () => (new ReflectionClass($instance))->getProperty('app')->getValue($instance);

        $this->assertSame($this->app, $appResolver());

        $instance->bootstrap($newApp = tap(m::mock(Application::class), function ($app) {
            $app->expects('environment')->andReturn(true);
        }));

        $this->assertNotSame($this->app, $appResolver());
        $this->assertSame($newApp, $appResolver());
    }

    public function testReleaseRestoresThePreviousOwnerAndDisarmsTheReleasedBootstrapper(): void
    {
        $previousErrorHandler = get_error_handler();
        $previousExceptionHandler = get_exception_handler();
        $errorReporting = error_reporting();
        $displayErrors = ini_get('display_errors');

        $first = new RecordingHandleExceptions;
        $second = new RecordingHandleExceptions;
        $firstApp = $this->bootstrappableApplication();
        $secondApp = $this->bootstrappableApplication(testing: false);

        try {
            ini_set('display_errors', 'stderr');

            $first->bootstrap($firstApp);
            $second->bootstrap($secondApp);

            $errorHandler = get_error_handler();
            $exceptionHandler = get_exception_handler();

            // Bootstrapping the owning application again, as env:encrypt does, keeps its handlers.
            $second->bootstrap($secondApp);
            (new RecordingHandleExceptions)->bootstrap($secondApp);

            $this->assertSame($errorHandler, get_error_handler());
            $this->assertSame($exceptionHandler, get_exception_handler());
            $this->assertSame('Off', ini_get('display_errors'));

            HandleExceptions::release($secondApp);

            $this->assertSame('stderr', ini_get('display_errors'));
            $this->assertSame($this->installedHandler($first, 'errorHandler'), get_error_handler());
            $this->assertSame($this->installedHandler($first, 'exceptionHandler'), get_exception_handler());

            $exception = new RuntimeException('Released handler.');

            try {
                $exceptionHandler($exception);
                $this->fail('Expected the released exception handler to rethrow the exception.');
            } catch (RuntimeException $caught) {
                $this->assertSame($exception, $caught);
            }

            $this->assertFalse($errorHandler(E_USER_WARNING, 'Released handler.', __FILE__, __LINE__));

            $this->shutdownForwarder($second)();
            $this->shutdownForwarder($first)();

            $this->assertSame(0, $second->shutdowns);
            $this->assertSame(1, $first->shutdowns);

            HandleExceptions::release($firstApp);

            $this->assertSame($previousErrorHandler, get_error_handler());
            $this->assertSame($previousExceptionHandler, get_exception_handler());
        } finally {
            $this->restoreHandlers($previousErrorHandler, $previousExceptionHandler);
            error_reporting($errorReporting);
            ini_set('display_errors', $displayErrors);
        }
    }

    public function testReleaseLeavesHandlersAndSettingsChangedAfterTheApplication(): void
    {
        $previousErrorHandler = get_error_handler();
        $previousExceptionHandler = get_exception_handler();
        $errorReporting = error_reporting();
        $displayErrors = ini_get('display_errors');

        $bootstrapper = new RecordingHandleExceptions;
        $app = $this->bootstrappableApplication(testing: false);
        $laterErrorHandler = static fn (): bool => true;

        try {
            ini_set('display_errors', 'stderr');

            $bootstrapper->bootstrap($app);

            $errorHandler = get_error_handler();

            set_error_handler($laterErrorHandler);
            ini_set('display_errors', '1');

            HandleExceptions::release($app);

            $this->assertSame($laterErrorHandler, get_error_handler());
            $this->assertSame($previousExceptionHandler, get_exception_handler());
            $this->assertSame('1', ini_get('display_errors'));
            $this->assertFalse($errorHandler(E_USER_WARNING, 'Released handler.', __FILE__, __LINE__));
        } finally {
            $this->restoreHandlers($previousErrorHandler, $previousExceptionHandler);
            error_reporting($errorReporting);
            ini_set('display_errors', $displayErrors);
        }
    }

    /**
     * Create an application mock that the bootstrapper can install its handlers for.
     */
    protected function bootstrappableApplication(bool $testing = true): Application
    {
        return tap(m::mock(Application::class), function (Application $app) use ($testing): void {
            $app->allows('environment')->with('testing')->andReturn($testing);
        });
    }

    /**
     * Get a handler installed by the given bootstrapper.
     */
    protected function installedHandler(HandleExceptions $bootstrapper, string $property): mixed
    {
        return (new ReflectionClass($bootstrapper))->getProperty($property)->getValue($bootstrapper);
    }

    /**
     * Get a shutdown forwarder equivalent to the one the bootstrapper registers.
     */
    protected function shutdownForwarder(HandleExceptions $bootstrapper): callable
    {
        return (new ReflectionMethod($bootstrapper, 'forwardsTo'))->invoke($bootstrapper, 'handleShutdown');
    }

    /**
     * Restore the handlers that were active before the test installed its own.
     */
    protected function restoreHandlers(?callable $errorHandler, ?callable $exceptionHandler): void
    {
        while (get_exception_handler() !== $exceptionHandler && get_exception_handler() !== null) {
            restore_exception_handler();
        }

        while (get_error_handler() !== $errorHandler && get_error_handler() !== null) {
            restore_error_handler();
        }
    }
}

class CustomNullHandler extends NullHandler
{
}

class RecordingHandleExceptions extends HandleExceptions
{
    /**
     * The number of shutdowns this bootstrapper handled.
     */
    public int $shutdowns = 0;

    /**
     * Record the PHP shutdown event.
     */
    public function handleShutdown(): void
    {
        ++$this->shutdowns;
    }
}
