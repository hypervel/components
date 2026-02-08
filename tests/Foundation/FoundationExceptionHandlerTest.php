<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation;

use Exception;
use Hyperf\Contract\SessionInterface;
use Hyperf\Di\MethodDefinitionCollector;
use Hyperf\Di\MethodDefinitionCollectorInterface;
use Hyperf\HttpMessage\Exception\HttpException;
use Hyperf\HttpMessage\Server\Response as Psr7Response;
use Hyperf\HttpMessage\Uri\Uri;
use Hyperf\View\RenderInterface;
use Hyperf\ViewEngine\Contract\FactoryInterface;
use Hyperf\ViewEngine\ViewErrorBag;
use Hypervel\Config\Repository;
use Hypervel\Context\ApplicationContext;
use Hypervel\Context\Context;
use Hypervel\Context\RequestContext;
use Hypervel\Context\ResponseContext;
use Hypervel\Contracts\Config\Repository as ConfigContract;
use Hypervel\Contracts\Http\Response as ResponseContract;
use Hypervel\Contracts\Router\UrlGenerator as UrlGeneratorContract;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Contracts\Support\Responsable;
use Hypervel\Database\Eloquent\ModelNotFoundException;
use Hypervel\Foundation\Exceptions\Handler;
use Hypervel\Http\Request;
use Hypervel\Http\Response;
use Hypervel\HttpMessage\Exceptions\AccessDeniedHttpException;
use Hypervel\Support\Facades\View;
use Hypervel\Support\MessageBag;
use Hypervel\Tests\Foundation\Concerns\HasMockedApplication;
use Hypervel\Tests\TestCase;
use Hypervel\Validation\ValidationException;
use Hypervel\Validation\Validator;
use InvalidArgumentException;
use Mockery as m;
use OutOfRangeException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use RuntimeException;
use stdClass;
use Swow\Psr7\Message\ServerRequestPlusInterface;
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
            ConfigContract::class => fn () => $this->config,
            FactoryInterface::class => fn () => new stdClass(),
            Request::class => fn () => $this->request,
            ServerRequestInterface::class => fn () => m::mock(ServerRequestInterface::class),
            ResponseContract::class => fn () => new Response(),
            MethodDefinitionCollectorInterface::class => fn () => new MethodDefinitionCollector(),
        ]);

        ResponseContext::set(new Psr7Response());
        ApplicationContext::setContainer($this->container);
        View::shouldReceive('replaceNamespace')->once();
        Context::destroy(SessionInterface::class);

        $this->handler = new Handler($this->container);
    }

    public function tearDown(): void
    {
        parent::tearDown();

        Context::destroy('__request.root.uri');
        Context::destroy(ServerRequestInterface::class);
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
        )->getBody()->getContents();

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

        $response = $this->handler->render($this->request, new CustomException())->getBody()->getContents();

        $this->assertSame('{"response":"My custom exception response"}', $response);
    }

    public function testReturnsCustomResponseFromCallableClass()
    {
        $this->handler->renderable(new CustomRenderer());

        $response = $this->handler->render($this->request, new CustomException())->getBody()->getContents();

        $this->assertSame('{"response":"The CustomRenderer response"}', $response);
    }

    public function testReturnsResponseFromRenderableException()
    {
        $response = $this->handler->render($this->request, new RenderableException())->getBody()->getContents();

        $this->assertSame('{"response":"My renderable exception response"}', $response);
    }

    public function testReturnsResponseFromMappedRenderableException()
    {
        $this->handler->map(RuntimeException::class, RenderableException::class);

        $response = $this->handler->render($this->request, new RuntimeException())->getBody()->getContents();

        $this->assertSame('{"response":"My renderable exception response"}', $response);
    }

    public function testReturnsCustomResponseWhenExceptionImplementsResponsable()
    {
        $response = $this->handler->render($this->request, new ResponsableException())->getBody()->getContents();

        $this->assertSame('{"response":"My responsable exception response"}', $response);
    }

    public function testReturnsJsonWithoutStackTraceWhenAjaxRequestAndDebugFalseAndExceptionMessageIsMasked()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);
        $this->config->set('app.debug', false);

        $response = $this->handler->render($this->request, new Exception('This error message should not be visible'))->getBody()->getContents();

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

        $response = $this->handler->render($this->request, new HttpException(403, 'My custom error message'))->getBody()->getContents();

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

        $response = $this->handler->render($this->request, new AccessDeniedHttpException('My custom error message'))->getBody()->getContents();

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

        $psr7Request = m::mock(ServerRequestPlusInterface::class);
        $psr7Request->shouldReceive('getUri')->andReturn(new Uri('http://localhost'));
        $this->container->instance(ServerRequestInterface::class, $psr7Request);
        RequestContext::set($psr7Request);

        $session = m::mock(SessionContract::class);
        $session->shouldReceive('get')->with('errors', m::type(ViewErrorBag::class))->andReturn(new MessageBag(['error' => 'My custom validation exception']));
        $session->shouldReceive('flash')->with('errors', m::type(ViewErrorBag::class))->once();
        $session->shouldReceive('flashInput')->with(['foo' => 'bar'])->once();
        Context::set(SessionInterface::class, $session);
        $this->container->instance(SessionContract::class, $session);

        $urlGenerator = m::mock(UrlGeneratorContract::class);
        $urlGenerator->shouldReceive('to')
            ->with('redirectTo')
            ->once()
            ->andReturn($redirectTo = 'http://localhost/redirectTo');
        $this->container->instance(UrlGeneratorContract::class, $urlGenerator);

        $validator = m::mock(Validator::class);
        $validator->shouldReceive('errors')->andReturn(new MessageBag(['error' => 'My custom validation exception']));

        $validationException = new ValidationException($validator);
        $validationException->redirectTo = 'redirectTo';

        $response = $this->handler->render($this->request, $validationException);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame($redirectTo, $response->getHeaderLine('Location'));
    }

    public function testModelNotFoundReturns404WithoutReporting()
    {
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);
        $this->config->set('app.debug', true);

        $response = $this->handler->render($this->request, $exception = (new ModelNotFoundException())->setModel('foo'));

        $this->assertEquals(404, $response->getStatusCode());
        $this->assertStringContainsString('"message":"No query results for model [foo]."', $response->getBody()->getContents());

        $logger = m::mock(LoggerInterface::class);
        $this->container->instance(LoggerInterface::class, $logger);
        $logger->shouldNotReceive('log');

        $this->handler->report($exception);
    }

    public function testItReturnsSpecificErrorViewIfExists()
    {
        $viewFactory = m::mock(FactoryInterface::class);
        $viewFactory->shouldReceive('exists')->with('errors::502')->andReturn(true);

        $this->container->instance(FactoryInterface::class, $viewFactory);

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
        $viewFactory = m::mock(FactoryInterface::class);
        $viewFactory->shouldReceive('exists')->once()->with('errors::502')->andReturn(false);
        $viewFactory->shouldReceive('exists')->once()->with('errors::5xx')->andReturn(true);

        $this->container->instance(FactoryInterface::class, $viewFactory);

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
        $viewFactory = m::mock(FactoryInterface::class);
        $viewFactory->shouldReceive('exists')->once()->with('errors::404')->andReturn(false);
        $viewFactory->shouldReceive('exists')->once()->with('errors::4xx')->andReturn(false);

        $this->container->instance(FactoryInterface::class, $viewFactory);

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

        $viewFactory = m::mock(FactoryInterface::class);
        $viewFactory->shouldReceive('exists')->once()->with('errors::404')->andReturn(true);

        $this->container->instance(FactoryInterface::class, $viewFactory);

        $renderer = m::mock(RenderInterface::class);
        $renderer->shouldReceive('render')->once()->withAnyArgs()->andThrow(new Exception('Rendering this view throws an exception'));
        $this->container->instance(RenderInterface::class, $renderer);
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

    public function testAfterResponseCallbacks()
    {
        $this->handler->afterResponse(function (ResponseInterface $response) {
            return $response->withHeader('X-After-Error', 'true');
        });
        $this->request->shouldReceive('expectsJson')->once()->andReturn(true);

        $response = $this->handler->render($this->request, new Exception('Test exception'));

        $this->assertTrue($response->hasHeader('X-After-Error'));
        $this->assertSame('true', $response->getHeaderLine('X-After-Error'));
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
    public function toResponse(Request $request): ResponseInterface
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
