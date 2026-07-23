<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc\GrpcRouterTest;

use Google\Protobuf\Any;
use Google\Protobuf\GPBEmpty;
use Google\Protobuf\Internal\Message;
use GPBMetadata\Google\Protobuf\GPBEmpty as GPBEmptyMetadata;
use Hypervel\Container\Container;
use Hypervel\Events\Dispatcher;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Server\ExceptionMapper;
use Hypervel\Grpc\Server\GrpcHttpResponse;
use Hypervel\Grpc\Server\GrpcRouter;
use Hypervel\Grpc\Server\GrpcRouteRegistrar;
use Hypervel\Grpc\Server\Middleware\HandleCall;
use Hypervel\Grpc\Server\Pipeline;
use Hypervel\Grpc\Server\ResponseFactory;
use Hypervel\Grpc\Server\ServerCallContext;
use Hypervel\Grpc\StatusCode;
use Hypervel\Http\Request;
use Hypervel\Pipeline\Pipeline as BasePipeline;
use Hypervel\Routing\CompiledRouteCollection;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Tests\Grpc\Fixtures\Metadata\TestService;
use Hypervel\Tests\Grpc\Fixtures\TestRequest;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use RuntimeException;

class GrpcRouterTest extends TestCase
{
    public function testValidatesEverySupportedActionShapeBeforeCompilation(): void
    {
        [$router, $registrar] = $this->router();
        $invokable = new GrpcRouterInvoker;
        $routes = [
            'closure' => $registrar->unary(
                'testing.Router/Closure',
                static fn (GPBEmpty $request): GPBEmpty => $request,
            ),
            'controller-array' => $registrar->unary(
                'testing.Router/ControllerArray',
                [GrpcRouterController::class, 'unary'],
            ),
            'controller-string' => $registrar->unary(
                'testing.Router/ControllerString',
                GrpcRouterController::class . '@unary',
            ),
            'invokable-class' => $registrar->unary(
                'testing.Router/InvokableClass',
                GrpcRouterInvoker::class,
            ),
            'invokable-object' => $registrar->unary(
                'testing.Router/InvokableObject',
                $invokable,
            ),
        ];

        foreach ($routes as $name => $route) {
            $route->name($name);
        }

        $router->compileAndWarm();

        $this->assertInstanceOf(CompiledRouteCollection::class, $router->getRoutes());

        foreach (array_keys($routes) as $name) {
            $route = $router->getRoutes()->getByName($name);

            $this->assertNotNull($route);
            $this->assertSame('request', $route->getAction('_grpc.request_parameter'));
            $this->assertSame(GPBEmpty::class, $route->getAction('_grpc.request_class'));
        }
    }

    #[PreserveGlobalState(false)]
    #[RunInSeparateProcess]
    public function testWarmsProtobufMessageDescriptorsBeforeWorkerFork(): void
    {
        $this->assertFalse(TestService::$is_initialized);
        $this->assertFalse(GPBEmptyMetadata::$is_initialized);
        [$router, $registrar] = $this->router();
        $route = $registrar->unary(
            'testing.Router/Warm',
            static fn (TestRequest $request): GPBEmpty => new GPBEmpty,
        );

        $router->compileAndWarm();

        $this->assertTrue(TestService::$is_initialized);
        $this->assertTrue(GPBEmptyMetadata::$is_initialized);
        $this->assertSame(TestRequest::class, $route->getAction('_grpc.request_class'));
    }

    public function testRejectsInvalidProtobufAndCallContextParameters(): void
    {
        foreach ([
            'missing request' => [
                static fn (): GPBEmpty => new GPBEmpty,
                'must declare exactly one protobuf request parameter',
            ],
            'multiple requests' => [
                static fn (GPBEmpty $request, Any $other): GPBEmpty => $request,
                'must declare exactly one protobuf request parameter',
            ],
            'nullable request' => [
                static fn (?GPBEmpty $request): GPBEmpty => $request ?? new GPBEmpty,
                'protobuf request parameter [$request] must have one non-nullable named type',
            ],
            'union request' => [
                static fn (GPBEmpty|Any $request): GPBEmpty => $request instanceof GPBEmpty ? $request : new GPBEmpty,
                'protobuf request parameter [$request] must have one non-nullable named type',
            ],
            'intersection request' => [
                static fn (GPBEmpty&GrpcRouterMarker $request): GPBEmpty => $request,
                'protobuf request parameter [$request] must have one non-nullable named type',
            ],
            'variadic request' => [
                static fn (GPBEmpty ...$request): GPBEmpty => $request[0],
                'protobuf request parameter [$request] must have one non-nullable named type',
            ],
            'request by reference' => [
                static function (GPBEmpty &$request): GPBEmpty {
                    return $request;
                },
                'protobuf request parameter [$request] must have one non-nullable named type',
            ],
            'abstract request' => [
                static fn (AbstractGrpcRequest $request): Message => $request,
                'must name a concrete protobuf message class',
            ],
            'nullable context' => [
                static fn (GPBEmpty $request, ?ServerCallContext $call): GPBEmpty => $request,
                'call context parameter [$call] must have one non-nullable named type',
            ],
            'multiple contexts' => [
                static fn (
                    GPBEmpty $request,
                    ServerCallContext $first,
                    ServerCallContext $second,
                ): GPBEmpty => $request,
                'may declare at most one call context parameter',
            ],
        ] as $case => [$action, $message]) {
            [$router, $registrar] = $this->router();
            $registrar->unary('testing.Invalid/Call', $action);

            try {
                $router->compileAndWarm();
                $this->fail("Expected the {$case} action to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertStringContainsString($message, $exception->getMessage(), $case);
            }
        }
    }

    public function testRejectsMissingAndNonPublicControllerActions(): void
    {
        foreach ([
            'missing class' => [
                ['MissingGrpcRouterController', 'unary'],
                'The gRPC route action class [MissingGrpcRouterController] does not exist.',
            ],
            'missing method' => [
                [GrpcRouterController::class, 'missing'],
                'The gRPC route action method [' . GrpcRouterController::class . '@missing] does not exist.',
            ],
            'private method' => [
                [GrpcRouterController::class, 'privateUnary'],
                'The gRPC route action method [' . GrpcRouterController::class . '@privateUnary] must be public.',
            ],
        ] as $case => [$action, $message]) {
            [$router, $registrar] = $this->router();
            $registrar->unary('testing.Invalid/Call', $action);

            try {
                $router->compileAndWarm();
                $this->fail("Expected the {$case} action to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }
    }

    public function testSynchronizesApplicationMiddlewareConfiguration(): void
    {
        $container = new Container;
        $application = new Router(new Dispatcher($container), $container);
        $application->aliasMiddleware('auth', GrpcRouterMiddleware::class);
        $application->middlewareGroup('service', ['auth', 'trace']);
        $application->middlewarePriority = ['trace', GrpcRouterMiddleware::class];
        $router = new InspectableGrpcRouter(new Dispatcher($container), $container);

        $router->syncMiddlewareFrom($application);

        $this->assertSame(GrpcRouterMiddleware::class, $router->getMiddleware()['auth']);
        $this->assertSame(['auth', 'trace'], $router->getMiddlewareGroups()['service']);
        $this->assertSame($application->middlewarePriority, $router->middlewarePriority);
    }

    public function testRetainsMandatoryCallHandlingWhenUserMiddlewareIsDisabled(): void
    {
        $container = new Container;
        $container->instance('middleware.disable', true);
        $router = new InspectableGrpcRouter(new Dispatcher($container), $container);
        $route = $router->post(
            'testing.Service/Call',
            static fn (GPBEmpty $request): GPBEmpty => $request,
        )->middleware(GrpcRouterMiddleware::class);

        $this->assertSame([HandleCall::class], $router->middlewareForTest($route));
        $this->assertInstanceOf(Pipeline::class, $router->newPipelineForTest());
    }

    public function testGrpcPipelineMapsTheClosestFailureOnce(): void
    {
        $container = new Container;
        $mapper = m::mock(ExceptionMapper::class);
        $failure = new RuntimeException('service failed');
        $mapped = new RpcException(StatusCode::Unknown, 'mapped');
        $mapper->shouldReceive('map')->once()->with($failure)->andReturn($mapped);
        $container->instance(ExceptionMapper::class, $mapper);
        $pipeline = new Pipeline($container);

        $result = $pipeline
            ->send(new GPBEmpty)
            ->through([
                static function () use ($failure): never {
                    throw $failure;
                },
            ])
            ->thenReturn();

        $this->assertSame($mapped, $result);
    }

    public function testResponsePreparationIsIdempotent(): void
    {
        $container = new Container;
        $factory = m::mock(ResponseFactory::class);
        $request = Request::create('/testing.Service/Call', 'POST');
        $expected = new GrpcHttpResponse('', ['grpc-status' => '0'], [], []);
        $value = new GPBEmpty;
        $factory->shouldReceive('make')->once()->with($request, $value)->andReturn($expected);
        $container->instance(ResponseFactory::class, $factory);
        $router = new GrpcRouter(new Dispatcher($container), $container);

        $response = $router->prepareResponse($request, $value);

        $this->assertSame($expected, $response);
        $this->assertSame($expected, $router->prepareResponse($request, $response));
    }

    /**
     * Create an isolated router and its public registrar.
     *
     * @return array{GrpcRouter, GrpcRouteRegistrar}
     */
    private function router(): array
    {
        $container = new Container;
        $router = new GrpcRouter(new Dispatcher($container), $container);

        return [$router, new GrpcRouteRegistrar($router)];
    }
}

interface GrpcRouterMarker
{
}

abstract class AbstractGrpcRequest extends Message
{
}

class GrpcRouterController
{
    public function unary(GPBEmpty $request, ServerCallContext $call): GPBEmpty
    {
        return $request;
    }

    private function privateUnary(GPBEmpty $request): GPBEmpty
    {
        return $request;
    }
}

class GrpcRouterInvoker
{
    public function __invoke(GPBEmpty $request): GPBEmpty
    {
        return $request;
    }
}

class InspectableGrpcRouter extends GrpcRouter
{
    public function newPipelineForTest(): BasePipeline
    {
        return $this->newPipeline();
    }

    public function middlewareForTest(Route $route): array
    {
        return $this->middlewareFor($route);
    }
}

class GrpcRouterMiddleware
{
}
