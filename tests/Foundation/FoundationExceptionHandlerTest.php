<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Closure;
use DateInterval;
use DateTimeInterface;
use Exception;
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
use Hypervel\Contracts\View\Factory as FactoryContract;
use Hypervel\Contracts\View\View as ViewContract;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Redirector;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Session\Store;
use Hypervel\Support\Facades\Facade;
use Hypervel\Support\Lottery;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\ValidationException;
use Hypervel\Validation\Validator;
use InvalidArgumentException;
use Mockery as m;
use OutOfRangeException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * @internal
 * @coversNothing
 */
class FoundationExceptionHandlerTest extends TestCase
{
    use HasMockedApplication;

    protected $config;

    protected $container;

    protected $handler;

    protected $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = $this->getConfig();
        $this->request = m::mock(Request::class);
        $this->container = $this->getApplication([
            'config' => fn () => $this->config,
            'view' => fn () => new stdClass(),
            Request::class => fn () => $this->request,
        ]);

        $this->container->instance(ResponseFactoryContract::class, new ResponseFactory(
            m::mock(FactoryContract::class),
            m::mock(Redirector::class),
        ));

        Container::setInstance($this->container);
        Context::destroy(Store::CONTEXT_KEY);

        $this->handler = new Handler($this->container);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        Lottery::determineResultNormally();
        Context::destroy('__request.root.uri');
        Facade::clearResolvedInstances();
    }

    public function testHandlerReportsExceptionAsContext()
    {
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('error')
            ->withArgs(['Exception message', m::hasKey('exception')])
            ->once();
        $this->container->instance(LoggerInterface::class, $logger);

        $this->handler->report(new RuntimeException('Exception message'));
    }

    public function testHandlerCallsContextMethodIfPresent()
    {
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->withArgs(['Exception message', m::subset(['foo' => 'bar'])])->once();
        $this->container->instance(LoggerInterface::class, $logger);

        $this->handler->report(new ContextProvidingException('Exception message'));
    }

    public function testHandlerReportsExceptionWhenUnReportable()
    {
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('error')->withArgs(['Exception message', m::hasKey('exception')])->once();
        $this->container->instance(LoggerInterface::class, $logger);

        $this->handler->report(new UnReportableException('Exception message'));
    }

    public function testHandlerReportsExceptionWithCustomLogLevel()
    {
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldReceive('critical')->withArgs(['Critical message', m::hasKey('exception')])->once();
        $logger->shouldReceive('error')->withArgs(['Error message', m::hasKey('exception')])->once();
        $logger->shouldReceive('log')->withArgs(['custom', 'Custom message', m::hasKey('exception')])->once();
        $this->container->instance(LoggerInterface::class, $logger);

        $this->handler->level(InvalidArgumentException::class, LogLevel::CRITICAL);
        $this->handler->level(OutOfRangeException::class, 'custom');

        $this->handler->report(new InvalidArgumentException('Critical message'));
        $this->handler->report(new RuntimeException('Error message'));
        $this->handler->report(new OutOfRangeException('Custom message'));
    }

    public function testHandlerIgnoresNotReportableExceptions()
    {
        $logger = m::mock(LoggerInterface::class);
        $logger->shouldNotReceive('log');
        $this->container->instance(LoggerInterface::class, $logger);

        $this->handler->ignore(RuntimeException::class);
        $this->handler->report(new RuntimeException('Exception message'));
    }

    public function testHandlerCallsReportMethodWithDependencies()
    {
        $reporter = m::mock(ReportingService::class);
        $reporter->shouldReceive('send')->withArgs(['Exception message'])->once();

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldNotReceive('log');

        $this->container->instance(ReportingService::class, $reporter);
        $this->container->instance(LoggerInterface::class, $logger);

        $this->handler->report(new ReportableException('Exception message'));
    }

    public function testHandlerReportsExceptionUsingCallableClass()
    {
        $reporter = m::mock(ReportingService::class);
        $reporter->shouldReceive('send')->withArgs(['Exception message'])->once();

        $logger = m::mock(LoggerInterface::class);
        $logger->shouldNotReceive('log');

        $this->container->instance(ReportingService::class, $reporter);
        $this->container->instance(LoggerInterface::class, $logger);

        $this->handler->reportable(new CustomReporter($reporter));
        $this->handler->report(new CustomException('Exception message'));
    }

    public function testShouldReturnJson()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('expectsJson')->once()->andReturn(true);
        $e = new Exception('My custom error message');

        $this->container->instance(Request::class, $request);

        $shouldReturnJson = (fn () => $this->shouldReturnJson($request, $e))->call($this->handler);
        $this->assertTrue($shouldReturnJson);

        $request->shouldReceive('expectsJson')->once()->andReturn(false);

        $shouldReturnJson = (fn () => $this->shouldReturnJson($request, $e))->call($this->handler);
        $this->assertFalse($shouldReturnJson);
    }

    public function testShouldReturnJsonWhen()
    {
        $request = m::mock(Request::class);
        $request->shouldReceive('expectsJson')->never();
        $exception = new Exception('My custom error message');

        $this->container->instance(Request::class, $request);

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
    }

    public function testReturnsJsonWithStackTraceWhenAjaxRequestAndDebugTrue()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);
        $this->config->set('app.debug', true);

        $response = $this->handler->render(
            $this->request,
            new Exception('My custom error message')
        )->getContent();

        $this->assertStringNotContainsString('<!DOCTYPE html>', $response);
        $this->assertStringContainsString('"message":"My custom error message"', $response);
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
        $response = $this->handler->render($this->request, new RenderableException())->getContent();

        $this->assertSame('{"response":"My renderable exception response"}', $response);
    }

    public function testReturnsResponseFromMappedRenderableException()
    {
        $this->handler->map(RuntimeException::class, RenderableException::class);

        $response = $this->handler->render($this->request, new RuntimeException())->getContent();

        $this->assertSame('{"response":"My renderable exception response"}', $response);
    }

    public function testReturnsCustomResponseWhenExceptionImplementsResponsable()
    {
        $response = $this->handler->render($this->request, new ResponsableException())->getContent();

        $this->assertSame('{"response":"My responsable exception response"}', $response);
    }

    public function testReturnsJsonWithoutStackTraceWhenAjaxRequestAndDebugFalseAndExceptionMessageIsMasked()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);
        $this->config->set('app.debug', false);

        $response = $this->handler->render($this->request, new Exception('This error message should not be visible'))->getContent();

        $this->assertStringContainsString('"message":"Server Error"', $response);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $response);
        $this->assertStringNotContainsString('This error message should not be visible', $response);
        $this->assertStringNotContainsString('"file":', $response);
        $this->assertStringNotContainsString('"line":', $response);
        $this->assertStringNotContainsString('"trace":', $response);
    }

    public function testReturnsJsonWithoutStackTraceWhenAjaxRequestAndDebugFalseAndHttpExceptionErrorIsShown()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);
        $this->config->set('app.debug', false);

        $response = $this->handler->render($this->request, new HttpException(403, 'My custom error message'))->getContent();

        $this->assertStringContainsString('"message":"My custom error message"', $response);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $response);
        $this->assertStringNotContainsString('"message":"Server Error"', $response);
        $this->assertStringNotContainsString('"file":', $response);
        $this->assertStringNotContainsString('"line":', $response);
        $this->assertStringNotContainsString('"trace":', $response);
    }

    public function testReturnsJsonWithoutStackTraceWhenAjaxRequestAndDebugFalseAndAccessDeniedHttpExceptionErrorIsShown()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);
        $this->config->set('app.debug', false);

        $response = $this->handler->render($this->request, new AccessDeniedHttpException('My custom error message'))->getContent();

        $this->assertStringContainsString('"message":"My custom error message"', $response);
        $this->assertStringNotContainsString('<!DOCTYPE html>', $response);
        $this->assertStringNotContainsString('"message":"Server Error"', $response);
        $this->assertStringNotContainsString('"file":', $response);
        $this->assertStringNotContainsString('"line":', $response);
        $this->assertStringNotContainsString('"trace":', $response);
    }

    public function testValidateFailed()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(false);
        $this->request->shouldReceive('all')->once()->andReturn(['foo' => 'bar']);

        $session = m::mock(SessionContract::class);
        $session->shouldReceive('get')->with('errors', m::type(ViewErrorBag::class))->andReturn(new MessageBag(['error' => 'My custom validation exception']));
        $session->shouldReceive('flash')->with('errors', m::type(ViewErrorBag::class))->once();
        $session->shouldReceive('flashInput')->with(['foo' => 'bar'])->once();
        Context::set(Store::CONTEXT_KEY, $session);
        $this->container->instance(SessionContract::class, $session);

        $redirectTo = 'http://localhost/redirectTo';
        $redirector = m::mock(Redirector::class);
        $redirector->shouldReceive('to')
            ->with('redirectTo', 302, [], null)
            ->once()
            ->andReturn(new RedirectResponse($redirectTo));
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
        $this->assertStringContainsString('"message":"No query results for model [foo]."', $response->getContent());

        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldNotReceive('log');

        $this->handler->report($exception);
    }

    public function testItReturnsSpecificErrorViewIfExists()
    {
        $viewFactory = m::mock(FactoryContract::class);
        $viewFactory->shouldReceive('exists')->with('errors::502')->andReturn(true);

        $this->container->instance('view', $viewFactory);

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
        $viewFactory = m::mock(FactoryContract::class);
        $viewFactory->shouldReceive('exists')->once()->with('errors::502')->andReturn(false);
        $viewFactory->shouldReceive('exists')->once()->with('errors::5xx')->andReturn(true);

        $this->container->instance('view', $viewFactory);

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
        $viewFactory = m::mock(FactoryContract::class);
        $viewFactory->shouldReceive('exists')->once()->with('errors::404')->andReturn(false);
        $viewFactory->shouldReceive('exists')->once()->with('errors::4xx')->andReturn(false);

        $this->container->instance('view', $viewFactory);

        $handler = new class($this->container) extends Handler {
            public function getErrorView($e)
            {
                return $this->getHttpExceptionView($e);
            }
        };

        $this->assertNull($handler->getErrorView(new HttpException(404)));
    }

    public function testItDoesNotCrashIfErrorViewThrowsWhileRenderingAndDebugTrue()
    {
        // When debug is true, it is OK to bubble the exception thrown while rendering
        // the error view as the debug handler should handle this gracefully.

        $view = m::mock(ViewContract::class);
        $view->shouldReceive('render')->once()->withAnyArgs()->andThrow(new Exception('Rendering this view throws an exception'));
        $viewFactory = m::mock(FactoryContract::class);
        $viewFactory->shouldReceive('exists')->once()->with('errors::404')->andReturn(true);
        $viewFactory->shouldReceive('make')->once()->with('errors::404', m::any())->andReturn($view);
        $this->container->instance('view', $viewFactory);
        $this->container->instance(ResponseFactoryContract::class, new ResponseFactory(
            $viewFactory,
            m::mock(Redirector::class),
        ));
        $this->config->set('app.debug', true);

        $handler = new class($this->container) extends Handler {
            protected function registerErrorViewPaths()
            {
            }

            public function getErrorView($e)
            {
                return $this->renderHttpException($e);
            }
        };

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Rendering this view throws an exception');

        $handler->getErrorView(new HttpException(404));
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
            public bool $attempted = false;

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
