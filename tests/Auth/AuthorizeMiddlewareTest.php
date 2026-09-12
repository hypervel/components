<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

use App\Models\Comment;
use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Gate;
use Hypervel\Auth\Middleware\Authorize;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Access\Gate as GateContract;
use Hypervel\Contracts\Routing\Registrar;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Events\Dispatcher;
use Hypervel\Http\Request;
use Hypervel\Routing\CallableDispatcher;
use Hypervel\Routing\Contracts\CallableDispatcher as CallableDispatcherContract;
use Hypervel\Routing\Middleware\SubstituteBindings;
use Hypervel\Routing\Router;
use Hypervel\Tests\Auth\Fixtures\AbilitiesEnum;
use Hypervel\Tests\TestCase;
use Mockery as m;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class AuthorizeMiddlewareTest extends TestCase
{
    protected Container $container;

    protected stdClass $user;

    protected Router $router;

    /**
     * Set up the authorization services.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new stdClass;

        Container::setInstance($this->container = new Container);

        $this->container->singleton(GateContract::class, function (): Gate {
            return new Gate($this->container, function (): stdClass {
                return $this->user;
            });
        });

        $this->router = new Router(new Dispatcher, $this->container);

        $this->container->bind(CallableDispatcherContract::class, fn (Container $app): CallableDispatcher => new CallableDispatcher($app));

        $this->container->instance(Registrar::class, $this->router);
    }

    public function testItCanGenerateDefinitionViaStaticMethod(): void
    {
        $signature = Authorize::using('ability');
        $this->assertSame('Hypervel\Auth\Middleware\Authorize:ability', $signature);

        $signature = Authorize::using('ability', 'model');
        $this->assertSame('Hypervel\Auth\Middleware\Authorize:ability,model', $signature);

        $signature = Authorize::using('ability', 'model', Comment::class);
        $this->assertSame('Hypervel\Auth\Middleware\Authorize:ability,model,App\Models\Comment', $signature);
    }

    public function testUsingWithBackedEnum(): void
    {
        $result = Authorize::using(AbilitiesEnum::ViewDashboard);

        $this->assertSame(Authorize::class . ':view-dashboard', $result);
    }

    public function testUsingWithBackedEnumAndModels(): void
    {
        $result = Authorize::using(AbilitiesEnum::ViewDashboard, 'App\Models\User');

        $this->assertSame(Authorize::class . ':view-dashboard,App\Models\User', $result);
    }

    public function testUsingWithUnitEnum(): void
    {
        $result = Authorize::using(AuthorizeMiddlewareTestUnitEnum::ManageUsers);

        $this->assertSame(Authorize::class . ':ManageUsers', $result);
    }

    public function testUsingWithUnitEnumAndModels(): void
    {
        $result = Authorize::using(AuthorizeMiddlewareTestUnitEnum::ViewReports, 'App\Models\Report');

        $this->assertSame(Authorize::class . ':ViewReports,App\Models\Report', $result);
    }

    public function testUsingWithIntBackedEnum(): void
    {
        $result = Authorize::using(AuthorizeMiddlewareTestIntBackedEnum::CreatePost);

        $this->assertSame(Authorize::class . ':1', $result);
    }

    public function testUsingWithStringAbilityAndMultipleModels(): void
    {
        $result = Authorize::using('transfer', 'App\Models\Account', 'App\Models\User');

        $this->assertSame(Authorize::class . ':transfer,App\Models\Account,App\Models\User', $result);
    }

    public function testSimpleAbilityUnauthorized(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $this->gate()->define('view-dashboard', function (stdClass $user, mixed $additional = null): bool {
            $this->assertNull($additional);

            return false;
        });

        $this->router->get('dashboard', [
            'middleware' => Authorize::class . ':view-dashboard',
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $this->router->dispatch(Request::create('dashboard', 'GET'));
    }

    public function testSimpleAbilityAuthorized(): void
    {
        $this->gate()->define('view-dashboard', function (stdClass $user): bool {
            return true;
        });

        $this->router->get('dashboard', [
            'middleware' => Authorize::class . ':view-dashboard',
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithStringParameter(): void
    {
        $this->gate()->define('view-dashboard', function (stdClass $user, string $param): bool {
            return $param === 'some string';
        });

        $this->router->get('dashboard', [
            'middleware' => Authorize::class . ':view-dashboard,"some string"',
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithBackedEnumParameter(): void
    {
        $this->gate()->define('view-dashboard', function (stdClass $user): bool {
            return true;
        });

        $this->router->middleware(Authorize::using(AbilitiesEnum::ViewDashboard))->get('dashboard', [
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithNullParameter(): void
    {
        $this->gate()->define('view-dashboard', function (stdClass $user, mixed $param = null): bool {
            $this->assertNull($param);

            return true;
        });

        $this->router->get('dashboard', [
            'middleware' => Authorize::class . ':view-dashboard,null',
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $this->router->dispatch(Request::create('dashboard', 'GET'));
    }

    public function testSimpleAbilityWithOptionalParameter(): void
    {
        $post = new stdClass;

        $this->router->bind('post', function () use ($post): stdClass {
            return $post;
        });

        $this->gate()->define('view-comments', function (stdClass $user, ?stdClass $model = null): bool {
            return true;
        });

        $middleware = [SubstituteBindings::class, Authorize::class . ':view-comments,post'];

        $this->router->get('comments', [
            'middleware' => $middleware,
            'uses' => function (): string {
                return 'success';
            },
        ]);
        $this->router->get('posts/{post}/comments', [
            'middleware' => $middleware,
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('posts/1/comments', 'GET'));
        $this->assertSame('success', $response->content());

        $response = $this->router->dispatch(Request::create('comments', 'GET'));
        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithStringParameterFromRouteParameter(): void
    {
        $this->gate()->define('view-dashboard', function (stdClass $user, string $param): bool {
            return $param === 'true';
        });

        $this->router->get('dashboard/{route_parameter}', [
            'middleware' => Authorize::class . ':view-dashboard,route_parameter',
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard/true', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithStringParameter0FromRouteParameter(): void
    {
        $this->gate()->define('view-dashboard', function (stdClass $user, string $param): bool {
            return $param === '0';
        });

        $this->router->get('dashboard/{route_parameter}', [
            'middleware' => Authorize::class . ':view-dashboard,route_parameter',
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard/0', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testModelTypeUnauthorized(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $this->gate()->define('create', function (stdClass $user, string $model): bool {
            $this->assertSame('App\User', $model);

            return false;
        });

        $this->router->get('users/create', [
            'middleware' => [SubstituteBindings::class, Authorize::class . ':create,App\User'],
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $this->router->dispatch(Request::create('users/create', 'GET'));
    }

    public function testModelTypeAuthorized(): void
    {
        $this->gate()->define('create', function (stdClass $user, string $model): bool {
            $this->assertSame('App\User', $model);

            return true;
        });

        $this->router->get('users/create', [
            'middleware' => Authorize::class . ':create,App\User',
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('users/create', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testModelUnauthorized(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $post = new stdClass;

        $this->router->bind('post', function () use ($post): stdClass {
            return $post;
        });

        $this->gate()->define('edit', function (stdClass $user, stdClass $model) use ($post): bool {
            $this->assertSame($model, $post);

            return false;
        });

        $this->router->get('posts/{post}/edit', [
            'middleware' => [SubstituteBindings::class, Authorize::class . ':edit,post'],
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $this->router->dispatch(Request::create('posts/1/edit', 'GET'));
    }

    public function testModelAuthorized(): void
    {
        $post = new stdClass;

        $this->router->bind('post', function () use ($post): stdClass {
            return $post;
        });

        $this->gate()->define('edit', function (stdClass $user, stdClass $model) use ($post): bool {
            $this->assertSame($model, $post);

            return true;
        });

        $this->router->get('posts/{post}/edit', [
            'middleware' => [SubstituteBindings::class, Authorize::class . ':edit,post'],
            'uses' => function (): string {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('posts/1/edit', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testModelInstanceAsParameter(): void
    {
        $instance = m::mock(Model::class);

        $this->gate()->define('success', function (stdClass $user, Model $model) use ($instance): bool {
            $this->assertSame($model, $instance);

            return true;
        });

        $request = m::mock(Request::class);

        $next = function (): Response {
            return new Response;
        };

        (new Authorize($this->gate()))
            ->handle($request, $next, 'success', $instance);
    }

    /**
     * Get the Gate instance from the container.
     */
    protected function gate(): GateContract
    {
        return $this->container->make(GateContract::class);
    }
}

enum AuthorizeMiddlewareTestUnitEnum
{
    case ManageUsers;
    case ViewReports;
}

enum AuthorizeMiddlewareTestIntBackedEnum: int
{
    case CreatePost = 1;
    case DeletePost = 2;
}
