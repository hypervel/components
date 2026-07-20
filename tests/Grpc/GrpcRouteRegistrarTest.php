<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc;

use Google\Protobuf\GPBEmpty;
use Hypervel\Container\Container;
use Hypervel\Events\Dispatcher;
use Hypervel\Grpc\Server\GrpcRouter;
use Hypervel\Grpc\Server\GrpcRouteRegistrar;
use Hypervel\Grpc\Server\PendingGrpcRegistration;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

class GrpcRouteRegistrarTest extends TestCase
{
    public function testRegistersCanonicalUnaryAndServerStreamingRoutes(): void
    {
        [$registrar, $router] = $this->registrar();
        $action = static fn (): GPBEmpty => new GPBEmpty;

        $unary = $registrar->unary('/helloworld.Greeter/SayHello', $action)
            ->middleware('auth')
            ->name('grpc.greeter.say-hello');
        $stream = $registrar->serverStream('helloworld.Greeter/ListGreetings', $action);

        $this->assertSame(['POST'], $unary->methods());
        $this->assertSame('helloworld.Greeter/SayHello', $unary->uri());
        $this->assertSame('grpc.greeter.say-hello', $unary->getName());
        $this->assertSame(['auth'], $unary->middleware());
        $this->assertSame([
            'service' => 'helloworld.Greeter',
            'method' => 'SayHello',
            'server_streaming' => false,
        ], $unary->getAction('_grpc'));
        $this->assertSame([
            'service' => 'helloworld.Greeter',
            'method' => 'ListGreetings',
            'server_streaming' => true,
        ], $stream->getAction('_grpc'));
        $this->assertSame([$unary, $stream], $router->getRoutes()->getRoutes());
    }

    public function testServiceRegistersRelativeMethodsAndRestoresItsContext(): void
    {
        [$registrar] = $this->registrar();
        $routes = [];

        $registrar->service('helloworld.Greeter', function () use ($registrar, &$routes): void {
            $routes[] = $registrar->unary('SayHello', static fn (): GPBEmpty => new GPBEmpty);
            $routes[] = $registrar->serverStream('ListGreetings', static fn (): iterable => []);
        });

        $outside = $registrar->unary('other.Service/Call', static fn (): GPBEmpty => new GPBEmpty);

        $this->assertSame('helloworld.Greeter/SayHello', $routes[0]->uri());
        $this->assertSame('helloworld.Greeter/ListGreetings', $routes[1]->uri());
        $this->assertSame('other.Service/Call', $outside->uri());
    }

    public function testServiceRejectsNestedGroupsAndRestoresItsContextAfterFailure(): void
    {
        [$registrar] = $this->registrar();

        try {
            $registrar->service('first.Service', function () use ($registrar): void {
                $registrar->service('nested.Service', static function (): void {
                });
            });
            $this->fail('Expected the nested service group to fail.');
        } catch (LogicException $exception) {
            $this->assertSame('Nested gRPC service groups are not supported.', $exception->getMessage());
        }

        try {
            $registrar->service('throwing.Service', static function (): never {
                throw new RuntimeException('route loading failed');
            });
            $this->fail('Expected the service route failure to propagate.');
        } catch (RuntimeException $exception) {
            $this->assertSame('route loading failed', $exception->getMessage());
        }

        $route = $registrar->unary('healthy.Service/Call', static fn (): GPBEmpty => new GPBEmpty);

        $this->assertSame('healthy.Service/Call', $route->uri());
    }

    public function testServiceRequiresRelativeMethodsAndValidIdentifiers(): void
    {
        [$registrar] = $this->registrar();

        foreach ([
            '' => 'The gRPC service name is invalid.',
            'invalid..Service' => 'The gRPC service name is invalid.',
        ] as $service => $message) {
            try {
                $registrar->service($service, static function (): void {
                });
                $this->fail("Expected service [{$service}] to be rejected.");
            } catch (InvalidArgumentException $exception) {
                $this->assertSame($message, $exception->getMessage());
            }
        }

        $registrar->service('valid.Service', function () use ($registrar): void {
            try {
                $registrar->unary('other.Service/Call', static fn (): GPBEmpty => new GPBEmpty);
                $this->fail('Expected a qualified method inside a service group to fail.');
            } catch (InvalidArgumentException $exception) {
                $this->assertSame('The gRPC method name is invalid.', $exception->getMessage());
            }
        });
    }

    public function testPendingRegistrationIsImmutableAndAppliesOnlyNarrowRouteAttributes(): void
    {
        [$registrar] = $this->registrar();
        $action = static fn (): GPBEmpty => new GPBEmpty;
        $pending = $registrar->middleware(['auth', 'trace'])->name('grpc.');

        $unary = $pending->withoutMiddleware('trace')->unary('testing.Service/Unary', $action)->name('unary');
        $stream = $pending->serverStream('testing.Service/Stream', $action)->name('stream');

        $this->assertSame(['auth', 'trace'], $unary->middleware());
        $this->assertSame(['trace'], $unary->excludedMiddleware());
        $this->assertSame('grpc.unary', $unary->getName());
        $this->assertSame(['auth', 'trace'], $stream->middleware());
        $this->assertSame([], $stream->excludedMiddleware());
        $this->assertSame('grpc.stream', $stream->getName());
        $this->assertFalse(method_exists($pending, 'get'));
        $this->assertFalse(method_exists($pending, 'post'));
        $this->assertFalse(method_exists($pending, 'dispatch'));
    }

    public function testPendingRegistrationAppliesAttributesToAWholeService(): void
    {
        [$registrar] = $this->registrar();
        $routes = [];

        $registrar->middleware('auth')
            ->withoutMiddleware('web')
            ->name('grpc.health.')
            ->service('grpc.health.v1.Health', function () use ($registrar, &$routes): void {
                $routes[] = $registrar->unary('Check', static fn (): GPBEmpty => new GPBEmpty)->name('check');
                $routes[] = $registrar->unary('List', static fn (): GPBEmpty => new GPBEmpty)->name('list');
            });

        foreach ($routes as $route) {
            $this->assertSame(['auth'], $route->middleware());
            $this->assertSame(['web'], $route->excludedMiddleware());
        }

        $this->assertSame('grpc.health.check', $routes[0]->getName());
        $this->assertSame('grpc.health.list', $routes[1]->getName());
    }

    public function testRegistrarDoesNotExposeTheHttpRouterSurface(): void
    {
        [$registrar] = $this->registrar();

        $this->assertFalse(method_exists($registrar, 'get'));
        $this->assertFalse(method_exists($registrar, 'post'));
        $this->assertFalse(method_exists($registrar, 'dispatch'));
        $this->assertFalse(method_exists($registrar, 'withAttributes'));
        $this->assertInstanceOf(PendingGrpcRegistration::class, $registrar->middleware('auth'));
    }

    /**
     * Create an isolated router and public registrar.
     *
     * @return array{GrpcRouteRegistrar, GrpcRouter}
     */
    private function registrar(): array
    {
        $container = new Container;
        $router = new GrpcRouter(new Dispatcher($container), $container);

        return [new GrpcRouteRegistrar($router), $router];
    }
}
