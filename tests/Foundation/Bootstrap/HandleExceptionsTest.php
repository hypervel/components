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
use Mockery as m;
use Monolog\Handler\NullHandler;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;
use Symfony\Component\Console\Output\StreamOutput;

class HandleExceptionsTest extends TestCase
{
    protected $app;

    protected Config $config;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = m::mock(Application::setInstance(new Application));

        $this->app->instance('config', $this->config = new Config);
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
            '/home/user/laravel/routes/web.php',
            17
        ));

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/laravel/routes/web.php',
            17
        );
    }

    public function testPhpDeprecationsWithStackTraces()
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
            m::on(fn (string $message) => (bool) preg_match(
                <<<'REGEXP'
                #ErrorException: str_contains\(\): Passing null to parameter \#2 \(\$needle\) of type string is deprecated in /home/user/laravel/routes/web\.php:17
                Stack trace:
                \#0 .*helpers.php\(.*\): Hypervel\\Foundation\\Bootstrap\\HandleExceptions.*
                \#1 .*HandleExceptions\.php\(.*\): with.*
                \#2 .*HandleExceptions\.php\(.*\): Hypervel\\Foundation\\Bootstrap\\HandleExceptions->handleDeprecation.*
                \#3 .*HandleExceptionsTest\.php\(.*\): Hypervel\\Foundation\\Bootstrap\\HandleExceptions->handleError.*
                [\s\S]*#i
                REGEXP,
                $message
            ))
        );

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/laravel/routes/web.php',
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
            '/home/user/laravel/routes/web.php',
            17
        ));

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/laravel/routes/web.php',
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
            '/home/user/laravel/routes/web.php',
            17
        ));

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/laravel/routes/web.php',
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
            '/home/user/laravel/routes/web.php',
            17
        ));

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/laravel/routes/web.php',
            17
        );
    }

    public function testUserDeprecationsWithStackTraces()
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
            m::on(fn (string $message) => (bool) preg_match(
                <<<'REGEXP'
                #ErrorException: str_contains\(\): Passing null to parameter \#2 \(\$needle\) of type string is deprecated in /home/user/laravel/routes/web\.php:17
                Stack trace:
                \#0 .*helpers.php\(.*\): Hypervel\\Foundation\\Bootstrap\\HandleExceptions.*
                \#1 .*HandleExceptions\.php\(.*\): with.*
                \#2 .*HandleExceptions\.php\(.*\): Hypervel\\Foundation\\Bootstrap\\HandleExceptions->handleDeprecation.*
                \#3 .*HandleExceptionsTest\.php\(.*\): Hypervel\\Foundation\\Bootstrap\\HandleExceptions->handleError.*
                [\s\S]*#i
                REGEXP,
                $message
            ))
        );

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/laravel/routes/web.php',
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
            '/home/user/laravel/routes/web.php',
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

        $this->handleExceptions()->handleError(
            E_USER_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/laravel/routes/web.php',
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
            '/home/user/laravel/routes/web.php',
            17
        );

        $this->assertSame(
            CustomNullHandler::class,
            $this->config->get('logging.channels.deprecations.handler')
        );
    }

    public function testNoDeprecationsDriverIfNoDeprecationsHereSend()
    {
        $this->assertNull($this->config->get('logging.deprecations'));
        $this->assertNull($this->config->get('logging.channels.deprecations'));
    }

    public function testErrors()
    {
        $logger = m::mock(LogManager::class);
        $this->app->instance(LogManager::class, $logger);

        $logger->shouldNotReceive('channel');
        $logger->shouldNotReceive('warning');

        $this->expectException(ErrorException::class);
        $this->expectExceptionMessage('Something went wrong');

        $this->handleExceptions()->handleError(
            E_ERROR,
            'Something went wrong',
            '/home/user/laravel/src/Providers/AppServiceProvider.php',
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

    public function testIgnoresDeprecationsWithoutAnApplication(): void
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

    public function testIgnoreDeprecationIfLoggerUnresolvable()
    {
        $this->app->bind(LogManager::class, fn () => throw new RuntimeException);
        $this->app->expects('runningUnitTests')->andReturn(false);
        $this->app->expects('hasBeenBootstrapped')->andReturn(true);

        $this->handleExceptions()->handleError(
            E_DEPRECATED,
            'str_contains(): Passing null to parameter #2 ($needle) of type string is deprecated',
            '/home/user/laravel/routes/web.php',
            17
        );
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
            '/home/user/laravel/routes/web.php',
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
            '/home/user/laravel/routes/web.php',
            17
        );

        Env::getRepository()->clear('LOG_DEPRECATIONS_WHILE_TESTING');
    }

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
}

class CustomNullHandler extends NullHandler
{
}
