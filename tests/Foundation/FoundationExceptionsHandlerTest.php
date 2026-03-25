<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Closure;
use DateInterval;
use DateTimeInterface;
use Exception;
use Hypervel\Cache\ArrayStore;
use Hypervel\Cache\NullStore;
use Hypervel\Cache\RateLimiter;
use Hypervel\Cache\RateLimiting\Limit;
use Hypervel\Cache\Repository as CacheRepository;
use Hypervel\Config\Repository;
use Hypervel\Container\Container;
use Hypervel\Context\Context;
use Hypervel\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Database\RecordsNotFoundException;
use Hypervel\Foundation\Application;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Foundation\Testing\Concerns\InteractsWithExceptionHandling;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Redirector;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Session\Store;
use Hypervel\Support\Carbon;
use Hypervel\Support\Lottery;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Testing\Assert;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\ValidationException;
use Hypervel\Validation\Validator;
use InvalidArgumentException;
use Mockery as m;
use OutOfRangeException;
use PHPUnit\Framework\AssertionFailedError;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;
use WeakReference;

use const UPLOAD_ERR_NO_FILE;

/**
 * @internal
 * @coversNothing
 */
class FoundationExceptionsHandlerTest extends TestCase
{
    use InteractsWithExceptionHandling;

    protected $config;

    protected $viewFactory;

    protected $container;

    protected $handler;

    protected $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->getConfig();
        $this->viewFactory = m::mock(ViewFactory::class);
        $this->request = m::mock(Request::class);
        $this->container = new Application();
        $this->container->singleton('config', fn () => $this->config);
        $this->container->singleton('view', fn () => $this->viewFactory);
        $this->container->singleton(Request::class, fn () => $this->request);

        $this->container->instance(ResponseFactoryContract::class, new ResponseFactory(
            $this->viewFactory,
            m::mock(Redirector::class),
        ));

        Container::setInstance($this->container);
        Context::forget(Store::CONTEXT_KEY);

        $this->handler = new Handler($this->container);
    }

    public function testHandlerReportsExceptionAsContext()
    {
        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldReceive('error')->withArgs(['Exception message', m::hasKey('exception')])->once();

        $this->handler->report(new RuntimeException('Exception message'));
    }

    public function testHandlerCallsContextMethodIfPresent()
    {
        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldReceive('error')->withArgs(['Exception message', m::subset(['foo' => 'bar'])])->once();

        $this->handler->report(new ContextProvidingException('Exception message'));
    }

    public function testHandlerReportsExceptionWhenUnReportable()
    {
        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldReceive('error')->withArgs(['Exception message', m::hasKey('exception')])->once();

        $this->handler->report(new UnReportableException('Exception message'));
    }

    public function testHandlerReportsExceptionWithCustomLogLevel()
    {
        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);

        $logger->shouldReceive('critical')->withArgs(['Critical message', m::hasKey('exception')])->once();
        $logger->shouldReceive('error')->withArgs(['Error message', m::hasKey('exception')])->once();
        $logger->shouldReceive('log')->withArgs(['custom', 'Custom message', m::hasKey('exception')])->once();

        $this->handler->level(InvalidArgumentException::class, LogLevel::CRITICAL);
        $this->handler->level(OutOfRangeException::class, 'custom');

        $this->handler->report(new InvalidArgumentException('Critical message'));
        $this->handler->report(new RuntimeException('Error message'));
        $this->handler->report(new OutOfRangeException('Custom message'));
    }

    public function testHandlerIgnoresNotReportableExceptions()
    {
        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldNotReceive('log');

        $this->handler->ignore(RuntimeException::class);

        $this->handler->report(new RuntimeException('Exception message'));
    }

    public function testHandlerCallsReportMethodWithDependencies()
    {
        $reporter = m::mock(ReportingService::class);
        $this->container->instance(ReportingService::class, $reporter);
        $reporter->shouldReceive('send')->withArgs(['Exception message'])->once();

        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldNotReceive('log');

        $this->handler->report(new ReportableException('Exception message'));
    }

    public function testHandlerReportsExceptionUsingCallableClass()
    {
        $reporter = m::mock(ReportingService::class);
        $reporter->shouldReceive('send')->withArgs(['Exception message'])->once();

        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldNotReceive('log');

        $this->handler->reportable(new CustomReporter($reporter));

        $this->handler->report(new CustomException('Exception message'));
    }

    public function testShouldReturnJson()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);
        $e = new Exception('My custom error message');

        $request = $this->request;

        $shouldReturnJson = (fn () => $this->shouldReturnJson($request, $e))->call($this->handler);
        $this->assertTrue($shouldReturnJson);

        $this->request->shouldReceive('expectsJson')->once()->andReturn(false);

        $shouldReturnJson = (fn () => $this->shouldReturnJson($request, $e))->call($this->handler);
        $this->assertFalse($shouldReturnJson);
    }

    public function testShouldReturnJsonWhen()
    {
        $this->request->shouldReceive('expectsJson')->never();
        $exception = new Exception('My custom error message');

        $request = $this->request;

        $this->handler->shouldRenderJsonWhen(function ($r, $e) use ($request, $exception) {
            $this->assertSame($request, $r);
            $this->assertSame($exception, $e);

            return true;
        });

        $shouldReturnJson = (fn () => $this->shouldReturnJson($request, $exception))->call($this->handler);
        $this->assertTrue($shouldReturnJson);

        $this->handler->shouldRenderJsonWhen(function ($r, $e) use ($request, $exception) {
            $this->assertSame($request, $r);
            $this->assertSame($exception, $e);

            return false;
        });

        $shouldReturnJson = (fn () => $this->shouldReturnJson($request, $exception))->call($this->handler);
        $this->assertFalse($shouldReturnJson);

        $this->assertSame(6, Assert::getCount());
    }

    public function testReturnsJsonWithStackTraceWhenAjaxRequestAndDebugTrue()
    {
        $this->config->set('app.debug', true);
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);

        $response = $this->handler->render($this->request, new Exception('My custom error message'))->getContent();

        $this->assertStringNotContainsString('<!DOCTYPE html>', $response);
        $this->assertStringContainsString('"message": "My custom error message"', $response);
        $this->assertStringContainsString('"file":', $response);
        $this->assertStringContainsString('"line":', $response);
        $this->assertStringContainsString('"trace":', $response);
    }

    public function testReturnsCustomResponseFromRenderableCallback()
    {
        $this->handler->renderable(function (CustomException $e, $request) {
            $this->assertSame($this->request, $request);

            return response()->json(['response' => 'My custom exception response']);
        });

        $response = $this->handler->render($this->request, new CustomException())->getContent();

        $this->assertSame('{"response":"My custom exception response"}', $response);
    }

    public function testReturnsCustomResponseFromCallableClass()
    {
        $this->handler->renderable(new CustomRenderer());

        $response = $this->handler->render($this->request, new CustomException())->getContent();

        $this->assertSame('{"response":"The CustomRenderer response"}', $response);
    }

    public function testReturnsResponseFromRenderableException()
    {
        $response = $this->handler->render(Request::create('/'), new RenderableException())->getContent();

        $this->assertSame('{"response":"My renderable exception response"}', $response);
    }

    public function testReturnsResponseFromMappedRenderableException()
    {
        $this->handler->map(RuntimeException::class, RenderableException::class);

        $response = $this->handler->render(Request::create('/'), new RuntimeException())->getContent();

        $this->assertSame('{"response":"My renderable exception response"}', $response);
    }

    public function testReturnsCustomResponseWhenExceptionImplementsResponsable()
    {
        $response = $this->handler->render($this->request, new ResponsableException())->getContent();

        $this->assertSame('{"response":"My responsable exception response"}', $response);
    }

    public function testReturnsJsonWithoutStackTraceWhenAjaxRequestAndDebugFalseAndExceptionMessageIsMasked()
    {
        $this->config->set('app.debug', false);
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);

        $response = $this->handler->render($this->request, new Exception('This error message should not be visible'))->getContent();

        $this->assertStringContainsString('"message": "Server Error"', $response);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $response);
        $this->assertStringNotContainsString('This error message should not be visible', $response);
        $this->assertStringNotContainsString('"file":', $response);
        $this->assertStringNotContainsString('"line":', $response);
        $this->assertStringNotContainsString('"trace":', $response);
    }

    public function testReturnsJsonWithoutStackTraceWhenAjaxRequestAndDebugFalseAndHttpExceptionErrorIsShown()
    {
        $this->config->set('app.debug', false);
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);

        $response = $this->handler->render($this->request, new HttpException(403, 'My custom error message'))->getContent();

        $this->assertStringContainsString('"message": "My custom error message"', $response);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $response);
        $this->assertStringNotContainsString('"message": "Server Error"', $response);
        $this->assertStringNotContainsString('"file":', $response);
        $this->assertStringNotContainsString('"line":', $response);
        $this->assertStringNotContainsString('"trace":', $response);
    }

    public function testReturnsJsonWithoutStackTraceWhenAjaxRequestAndDebugFalseAndAccessDeniedHttpExceptionErrorIsShown()
    {
        $this->config->set('app.debug', false);
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);

        $response = $this->handler->render($this->request, new AccessDeniedHttpException('My custom error message'))->getContent();

        $this->assertStringContainsString('"message": "My custom error message"', $response);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $response);
        $this->assertStringNotContainsString('"message": "Server Error"', $response);
        $this->assertStringNotContainsString('"file":', $response);
        $this->assertStringNotContainsString('"line":', $response);
        $this->assertStringNotContainsString('"trace":', $response);
    }

    public function testValidateFileMethod()
    {
        $argumentExpected = ['input' => 'My input value'];
        $argumentActual = null;

        $session = m::mock(Store::class);
        $session->shouldReceive('get')->with('errors', m::type(ViewErrorBag::class))->andReturn(new MessageBag(['error' => 'My custom validation exception']));
        $session->shouldReceive('flash')->with('errors', m::type(ViewErrorBag::class))->once();
        $session->shouldReceive('flashInput')->once()->with(m::on(
            function ($argument) use (&$argumentActual) {
                $argumentActual = $argument;

                return true;
            }
        ))->andReturnNull();
        Context::set(Store::CONTEXT_KEY, $session);
        $this->container->instance(SessionContract::class, $session);

        $this->container->singleton('redirect', function () use ($session) {
            $redirector = m::mock(Redirector::class);

            $redirect = new RedirectResponse('http://localhost/');
            $redirect->setSession($session);
            $redirector->shouldReceive('to')->once()
                ->andReturn($redirect);

            return $redirector;
        });

        $file = m::mock(UploadedFile::class);
        $file->shouldReceive('getPathname')->andReturn('photo.jpg');
        $file->shouldReceive('getClientOriginalName')->andReturn('photo.jpg');
        $file->shouldReceive('getClientMimeType')->andReturn('application/octet-stream');
        $file->shouldReceive('getError')->andReturn(UPLOAD_ERR_NO_FILE);

        $request = Request::create('/', 'POST', $argumentExpected, [], ['photo' => $file]);

        $validator = m::mock(Validator::class);
        $validator->shouldReceive('errors')->andReturn(new MessageBag(['error' => 'My custom validation exception']));

        $validationException = new ValidationException($validator);
        $validationException->redirectTo = '/';

        $this->handler->render($request, $validationException);

        $this->assertEquals($argumentExpected, $argumentActual);
    }

    public function testSuspiciousOperationReturns400WithoutReporting()
    {
        $this->config->set('app.debug', true);
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);

        $response = $this->handler->render($this->request, new SuspiciousOperationException('Invalid method override "__CONSTRUCT"'));

        $this->assertEquals(400, $response->getStatusCode());
        $this->assertStringContainsString('"message": "Bad request."', $response->getContent());

        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldNotReceive('log');

        $this->handler->report(new SuspiciousOperationException('Invalid method override "__CONSTRUCT"'));
    }

    public function testRecordsNotFoundReturns404WithoutReporting()
    {
        $this->config->set('app.debug', true);
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);

        $response = $this->handler->render($this->request, new RecordsNotFoundException());

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('"message": "Not found."', $response->getContent());

        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldNotReceive('log');

        $this->handler->report(new RecordsNotFoundException());
    }

    public function testItReturnsSpecificErrorViewIfExists()
    {
        $viewFactory = m::mock(ViewFactory::class);
        $viewFactory->shouldReceive('exists')->with('errors::502')->andReturn(true);

        $this->container->instance(ViewFactory::class, $viewFactory);

        $handler = new class($this->container) extends Handler {
            public function getErrorView($e)
            {
                return $this->getHttpExceptionView($e);
            }
        };

        $this->assertSame('errors::502', $handler->getErrorView(new HttpException(502)));
    }

    public function testItReturnsFallbackErrorViewIfExists()
    {
        $viewFactory = m::mock(ViewFactory::class);
        $viewFactory->shouldReceive('exists')->once()->with('errors::502')->andReturn(false);
        $viewFactory->shouldReceive('exists')->once()->with('errors::5xx')->andReturn(true);

        $this->container->instance(ViewFactory::class, $viewFactory);

        $handler = new class($this->container) extends Handler {
            public function getErrorView($e)
            {
                return $this->getHttpExceptionView($e);
            }
        };

        $this->assertSame('errors::5xx', $handler->getErrorView(new HttpException(502)));
    }

    public function testItReturnsNullIfNoErrorViewExists()
    {
        $viewFactory = m::mock(ViewFactory::class);
        $viewFactory->shouldReceive('exists')->once()->with('errors::404')->andReturn(false);
        $viewFactory->shouldReceive('exists')->once()->with('errors::4xx')->andReturn(false);

        $this->container->instance(ViewFactory::class, $viewFactory);

        $handler = new class($this->container) extends Handler {
            public function getErrorView($e)
            {
                return $this->getHttpExceptionView($e);
            }
        };

        $this->assertNull($handler->getErrorView(new HttpException(404)));
    }

    private function executeScenarioWhereErrorViewThrowsWhileRenderingAndDebugIs($debug)
    {
        $this->viewFactory->shouldReceive('exists')->once()->with('errors::404')->andReturn(true);
        $this->viewFactory->shouldReceive('make')->once()->withAnyArgs()->andThrow(new Exception('Rendering this view throws an exception'));

        $this->config->set('app.debug', $debug);

        $handler = new class($this->container) extends Handler {
            protected function registerErrorViewPaths(): void
            {
            }

            public function getErrorView($e)
            {
                return $this->renderHttpException($e);
            }
        };

        $this->assertInstanceOf(SymfonyResponse::class, $handler->getErrorView(new HttpException(404)));
    }

    public function testItDoesNotCrashIfErrorViewThrowsWhileRenderingAndDebugFalse()
    {
        // When debug is false, the exception thrown while rendering the error view
        // should not bubble as this may trigger an infinite loop.

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->once();
        $this->container->instance(LoggerInterface::class, $logger);

        $this->executeScenarioWhereErrorViewThrowsWhileRenderingAndDebugIs(false);
    }

    public function testItDoesNotCrashIfErrorViewThrowsWhileRenderingAndDebugTrue()
    {
        // When debug is true, it is OK to bubble the exception thrown while rendering
        // the error view as the debug handler should handle this gracefully.

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Rendering this view throws an exception');
        $this->executeScenarioWhereErrorViewThrowsWhileRenderingAndDebugIs(true);
    }

    public function testAssertExceptionIsThrown()
    {
        $this->assertThrows(function () {
            throw new Exception();
        });
        $this->assertThrows(function () {
            throw new CustomException();
        });
        $this->assertThrows(function () {
            throw new CustomException();
        }, CustomException::class);
        $this->assertThrows(function () {
            throw new Exception('Some message.');
        }, expectedMessage: 'Some message.');
        $this->assertThrows(function () {
            throw new CustomException('Some message.');
        }, expectedMessage: 'Some message.');
        $this->assertThrows(function () {
            throw new CustomException('Some message.');
        }, expectedClass: CustomException::class, expectedMessage: 'Some message.');

        try {
            $this->assertThrows(function () {
                throw new Exception();
            }, CustomException::class);
            $testFailed = true;
        } catch (AssertionFailedError) {
            $testFailed = false;
        }

        if ($testFailed) {
            Assert::fail('assertThrows failed: non matching exceptions are thrown.');
        }

        try {
            $this->assertThrows(function () {
                throw new Exception('Some message.');
            }, expectedClass: Exception::class, expectedMessage: 'Other message.');
            $testFailed = true;
        } catch (AssertionFailedError) {
            $testFailed = false;
        }

        if ($testFailed) {
            Assert::fail('assertThrows failed: non matching message are thrown.');
        }

        $this->assertThrows(function () {
            throw new CustomException('Some message.');
        }, function (CustomException $exception) {
            return $exception->getMessage() === 'Some message.';
        });

        try {
            $this->assertThrows(function () {
                throw new CustomException('Some message.');
            }, function (CustomException $exception) {
                return false;
            });
            $testFailed = true;
        } catch (AssertionFailedError) {
            $testFailed = false;
        }

        if ($testFailed) {
            Assert::fail('assertThrows failed: exception callback succeeded.');
        }

        try {
            $this->assertThrows(function () {
                throw new Exception('Some message.');
            }, function (CustomException $exception) {
                return true;
            });
            $testFailed = true;
        } catch (AssertionFailedError) {
            $testFailed = false;
        }

        if ($testFailed) {
            Assert::fail('assertThrows failed: non matching exceptions are thrown.');
        }
    }

    public function testAssertNoExceptionIsThrown()
    {
        try {
            $this->assertDoesntThrow(function () {
                throw new Exception();
            });

            $testFailed = true;
        } catch (AssertionFailedError) {
            $testFailed = false;
        }

        if ($testFailed) {
            Assert::fail('assertDoesntThrow failed: thrown exception was not detected.');
        }

        try {
            $this->assertDoesntThrow(function () {
            });

            $testFailed = false;
        } catch (AssertionFailedError) {
            $testFailed = true;
        }

        if ($testFailed) {
            Assert::fail('assertDoesntThrow failed: exception was detected while no exception was thrown.');
        }
    }

    public function testItReportsDuplicateExceptions()
    {
        $reported = [];
        $this->handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        $this->handler->report($one = new RuntimeException('foo'));
        $this->handler->report($one);
        $this->handler->report($two = new RuntimeException('foo'));

        $this->assertSame($reported, [$one, $one, $two]);
    }

    public function testItCanDedupeExceptions()
    {
        $reported = [];
        $e = new RuntimeException('foo');
        $this->handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        $this->handler->dontReportDuplicates();
        $this->handler->report($one = new RuntimeException('foo'));
        $this->handler->report($one);
        $this->handler->report($two = new RuntimeException('foo'));

        $this->assertSame($reported, [$one, $two]);
    }

    public function testItDedupesByObjectIdentityNotEquality()
    {
        $reported = [];
        $this->handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        $this->handler->dontReportDuplicates();

        // Create two exceptions at the same file and line so they are loosely equal.
        $one = new RuntimeException('foo');
        $two = new RuntimeException('foo');
        $this->assertEquals($one, $two, 'Precondition: exceptions should be loosely equal');
        $this->assertNotSame($one, $two, 'Precondition: exceptions should be different objects');

        $this->handler->report($one);
        $this->handler->report($two);

        $this->assertSame([$one, $two], $reported);
    }

    public function testDedupeMapIsPerRequestContext()
    {
        $reported = [];
        $this->handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        $this->handler->dontReportDuplicates();

        $exception = new RuntimeException('foo');
        $this->handler->report($exception);
        $this->handler->report($exception);

        $this->assertCount(1, $reported);

        // Simulate a new request by clearing the Context key.
        Context::forget(Handler::REPORTED_EXCEPTION_MAP_CONTEXT_KEY);

        $this->handler->report($exception);

        $this->assertCount(2, $reported);
        $this->assertSame([$exception, $exception], $reported);
    }

    public function testDedupeMapDoesNotPreventGarbageCollection()
    {
        $this->handler->reportable(function (Throwable $e) {
            return false;
        });
        $this->handler->dontReportDuplicates();

        $ref = null;
        (function () use (&$ref) {
            $exception = new RuntimeException('foo');
            $ref = WeakReference::create($exception);
            $this->handler->report($exception);
        })();

        // The exception is no longer referenced by any variable except the WeakMap.
        // WeakMap entries should not prevent garbage collection.
        $this->assertNull($ref->get());
    }

    public function testItCanSkipExceptionReportingUsingCallback()
    {
        $reported = [];
        $e1 = new RuntimeException('foo');
        $e2 = new RuntimeException('bar');

        $this->handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        $this->handler->dontReportWhen(function (Throwable $e) {
            return $e->getMessage() === 'foo';
        });

        $this->handler->report($e1);
        $this->handler->report($e2);
        $this->handler->report($e1);

        $this->assertSame($reported, [$e2]);
    }

    public function testItDoesNotThrottleExceptionsByDefault()
    {
        $reported = [];
        $this->handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        for ($i = 0; $i < 100; ++$i) {
            $this->handler->report(new RuntimeException("Exception {$i}"));
        }

        $this->assertCount(100, $reported);
    }

    public function testItDoesNotThrottleExceptionsWhenNullReturned()
    {
        $handler = new class($this->container) extends Handler {
            protected function throttle(Throwable $e): Lottery|Limit|null
            {
            }
        };
        $reported = [];
        $handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        for ($i = 0; $i < 100; ++$i) {
            $handler->report(new RuntimeException("Exception {$i}"));
        }

        $this->assertCount(100, $reported);
    }

    public function testItDoesNotThrottleExceptionsWhenUnlimitedLimit()
    {
        $handler = new class($this->container) extends Handler {
            protected function throttle(Throwable $e): Lottery|Limit|null
            {
                return Limit::none();
            }
        };
        $reported = [];
        $handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        for ($i = 0; $i < 100; ++$i) {
            $handler->report(new RuntimeException("Exception {$i}"));
        }

        $this->assertCount(100, $reported);
    }

    public function testItCanSampleExceptionsByClass()
    {
        $handler = new class($this->container) extends Handler {
            protected function throttle(Throwable $e): Lottery|Limit|null
            {
                return match (true) {
                    $e instanceof RuntimeException => Lottery::odds(2, 10),
                    default => parent::throttle($e),
                };
            }
        };
        Lottery::forceResultWithSequence([
            true, false, false, false, false,
            true, false, false, false, false,
        ]);
        $reported = [];
        $handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        for ($i = 0; $i < 10; ++$i) {
            $handler->report(new Exception("Exception {$i}"));
            $handler->report(new RuntimeException("RuntimeException {$i}"));
        }

        [$runtimeExceptions, $baseExceptions] = collect($reported)->partition(fn ($e) => $e instanceof RuntimeException);
        $this->assertCount(10, $baseExceptions);
        $this->assertCount(2, $runtimeExceptions);
    }

    public function testItRescuesExceptionsWhileThrottlingAndReports()
    {
        $handler = new class($this->container) extends Handler {
            protected function throttle(Throwable $e): Lottery|Limit|null
            {
                throw new RuntimeException('Something went wrong in the throttle method.');
            }
        };
        $reported = [];
        $handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });

        $handler->report(new Exception('Something in the app went wrong.'));

        $this->assertCount(1, $reported);
        $this->assertSame('Something in the app went wrong.', $reported[0]->getMessage());
    }

    public function testItRescuesExceptionsIfThereIsAnIssueResolvingTheRateLimiter()
    {
        $handler = new class($this->container) extends Handler {
            protected function throttle(Throwable $e): Lottery|Limit|null
            {
                return Limit::perDay(1);
            }
        };
        $reported = [];
        $handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });
        $resolved = false;
        $this->container->bind(RateLimiter::class, function () use (&$resolved) {
            $resolved = true;

            throw new Exception('Error resolving rate limiter.');
        });

        $handler->report(new Exception('Something in the app went wrong.'));

        $this->assertTrue($resolved);
        $this->assertCount(1, $reported);
        $this->assertSame('Something in the app went wrong.', $reported[0]->getMessage());
    }

    public function testItRescuesExceptionsIfThereIsAnIssueWithTheRateLimiter()
    {
        $handler = new class($this->container) extends Handler {
            protected function throttle(Throwable $e): Lottery|Limit|null
            {
                return Limit::perDay(1);
            }
        };
        $reported = [];
        $handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });
        $this->container->instance(RateLimiter::class, $limiter = new class(new CacheRepository(new NullStore())) extends RateLimiter {
            public $attempted = false;

            public function attempt(string $key, int $maxAttempts, Closure $callback, DateInterval|DateTimeInterface|int $decaySeconds = 60): mixed
            {
                $this->attempted = true;

                throw new Exception('Unable to connect to Redis.');
            }
        });

        $handler->report(new Exception('Something in the app went wrong.'));

        $this->assertTrue($limiter->attempted);
        $this->assertCount(1, $reported);
        $this->assertSame('Something in the app went wrong.', $reported[0]->getMessage());
    }

    public function testItCanRateLimitExceptions()
    {
        $handler = new class($this->container) extends Handler {
            protected function throttle(Throwable $e): Lottery|Limit|null
            {
                return Limit::perMinute(7);
            }
        };
        $reported = [];
        $handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });
        $this->container->instance(RateLimiter::class, $limiter = new class(new CacheRepository(new ArrayStore())) extends RateLimiter {
            public $attempted = 0;

            public function attempt(string $key, int $maxAttempts, Closure $callback, DateInterval|DateTimeInterface|int $decaySeconds = 60): mixed
            {
                ++$this->attempted;

                return parent::attempt(...func_get_args());
            }
        });
        Carbon::setTestNow(Carbon::now()->startOfDay());

        for ($i = 0; $i < 100; ++$i) {
            $handler->report(new Exception('Something in the app went wrong.'));
        }

        $this->assertSame(100, $limiter->attempted);
        $this->assertCount(7, $reported);
        $this->assertSame('Something in the app went wrong.', $reported[0]->getMessage());

        Carbon::setTestNow(Carbon::now()->addMinute());

        for ($i = 0; $i < 100; ++$i) {
            $handler->report(new Exception('Something in the app went wrong.'));
        }

        $this->assertSame(200, $limiter->attempted);
        $this->assertCount(14, $reported);
        $this->assertSame('Something in the app went wrong.', $reported[0]->getMessage());
    }

    public function testRateLimitExpiresOnBoundary()
    {
        $handler = new class($this->container) extends Handler {
            protected function throttle(Throwable $e): Lottery|Limit|null
            {
                return Limit::perMinute(1);
            }
        };
        $reported = [];
        $handler->reportable(function (Throwable $e) use (&$reported) {
            $reported[] = $e;

            return false;
        });
        $this->container->instance(RateLimiter::class, $limiter = new class(new CacheRepository(new ArrayStore())) extends RateLimiter {
            public $attempted = 0;

            public function attempt(string $key, int $maxAttempts, Closure $callback, DateInterval|DateTimeInterface|int $decaySeconds = 60): mixed
            {
                ++$this->attempted;

                return parent::attempt(...func_get_args());
            }
        });

        Carbon::setTestNow('2000-01-01 00:00:00.000');
        $handler->report(new Exception('Something in the app went wrong 1.'));
        Carbon::setTestNow('2000-01-01 00:00:59.999');
        $handler->report(new Exception('Something in the app went wrong 1.'));

        $this->assertSame(2, $limiter->attempted);
        $this->assertCount(1, $reported);
        $this->assertSame('Something in the app went wrong 1.', $reported[0]->getMessage());

        Carbon::setTestNow('2000-01-01 00:01:00.000');
        $handler->report(new Exception('Something in the app went wrong 2.'));
        Carbon::setTestNow('2000-01-01 00:01:59.999');
        $handler->report(new Exception('Something in the app went wrong 2.'));

        $this->assertSame(4, $limiter->attempted);
        $this->assertCount(2, $reported);
        $this->assertSame('Something in the app went wrong 2.', $reported[1]->getMessage());
    }

    public function testValidateFailed()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(false);
        $this->request->shouldReceive('input')->withNoArgs()->andReturn(['foo' => 'bar']);
        $this->request->shouldReceive('input')->with('_error_bag', m::any())->andReturn('default');

        $session = m::mock(Store::class);
        $session->shouldReceive('get')->with('errors', m::type(ViewErrorBag::class))->andReturn(new MessageBag(['error' => 'My custom validation exception']));
        $session->shouldReceive('flash')->with('errors', m::type(ViewErrorBag::class))->once();
        $session->shouldReceive('flashInput')->with(['foo' => 'bar'])->once();
        Context::set(Store::CONTEXT_KEY, $session);
        $this->container->instance(SessionContract::class, $session);

        $redirectTo = 'http://localhost/redirectTo';
        $redirector = m::mock(Redirector::class);
        $redirect = new RedirectResponse($redirectTo);
        $redirect->setSession($session);
        $redirector->shouldReceive('to')
            ->with('redirectTo', 302, [], null)
            ->once()
            ->andReturn($redirect);
        $this->container->instance('redirect', $redirector);

        $validator = m::mock(Validator::class);
        $validator->shouldReceive('errors')->andReturn(new MessageBag(['error' => 'My custom validation exception']));

        $validationException = new ValidationException($validator);
        $validationException->redirectTo = 'redirectTo';

        $response = $this->handler->render($this->request, $validationException);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($redirectTo, $response->headers->get('Location'));
    }

    public function testModelNotFoundReturns404WithoutReporting()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);
        $this->config->set('app.debug', true);

        $response = $this->handler->render($this->request, $exception = (new ModelNotFoundException())->setModel('foo'));

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('"message": "No query results for model [foo]."', $response->getContent());

        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldNotReceive('log');

        $this->handler->report($exception);
    }

    public function testAfterResponseCallbacks()
    {
        $this->handler->afterResponse(function (SymfonyResponse $response) {
            $response->headers->set('X-After-Error', 'true');
        });
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);

        $response = $this->handler->render($this->request, new Exception('Test exception'));

        $this->assertTrue($response->headers->has('X-After-Error'));
        $this->assertSame('true', $response->headers->get('X-After-Error'));
    }

    protected function getConfig(array $config = []): Repository
    {
        return new Repository(array_merge([
            'app' => ['url' => 'http://localhost'],
            'view' => ['config' => ['view_path' => 'view_path']],
        ], $config));
    }
}

class CustomException extends Exception
{
}

class ResponsableException extends Exception implements Responsable
{
    public function toResponse(Request $request): SymfonyResponse
    {
        return response()->json(['response' => 'My responsable exception response']);
    }
}

class ReportableException extends Exception
{
    public function report(ReportingService $reportingService)
    {
        $reportingService->send($this->getMessage());
    }
}

class UnReportableException extends Exception
{
    public function report()
    {
        return false;
    }
}

class RenderableException extends Exception
{
    public function render($request)
    {
        return response()->json(['response' => 'My renderable exception response']);
    }
}

class ContextProvidingException extends Exception
{
    public function context()
    {
        return [
            'foo' => 'bar',
        ];
    }
}

class CustomReporter
{
    private $service;

    public function __construct(ReportingService $service)
    {
        $this->service = $service;
    }

    public function __invoke(CustomException $e)
    {
        $this->service->send($e->getMessage());

        return false;
    }
}

class CustomRenderer
{
    public function __invoke(CustomException $e, $request)
    {
        return response()->json(['response' => 'The CustomRenderer response']);
    }
}

interface ReportingService
{
    public function send($message);
}
