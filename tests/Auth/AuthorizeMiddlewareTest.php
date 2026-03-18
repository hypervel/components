<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth;

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

/**
 * @internal
 * @coversNothing
 */
class AuthorizeMiddlewareTest extends TestCase
{
    protected $container;

    protected $user;

    protected $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = new stdClass();

        Container::setInstance($this->container = new Container());

        $this->container->singleton(GateContract::class, function () {
            return new Gate($this->container, function () {
                return $this->user;
            });
        });

        $this->router = new Router(new Dispatcher(), $this->container);

        $this->container->bind(CallableDispatcherContract::class, fn ($app) => new CallableDispatcher($app));

        $this->container->instance(Registrar::class, $this->router);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function testItCanGenerateDefinitionViaStaticMethod()
    {
        $signature = Authorize::using('ability');
        $this->assertSame('Hypervel\Auth\Middleware\Authorize:ability', $signature);

        $signature = Authorize::using('ability', 'model');
        $this->assertSame('Hypervel\Auth\Middleware\Authorize:ability,model', $signature);

        $signature = Authorize::using('ability', 'model', \App\Models\Comment::class);
        $this->assertSame('Hypervel\Auth\Middleware\Authorize:ability,model,App\Models\Comment', $signature);
    }

    public function testUsingWithBackedEnum()
    {
        $result = Authorize::using(AbilitiesEnum::ViewDashboard);

        $this->assertSame(Authorize::class . ':view-dashboard', $result);
    }

    public function testUsingWithBackedEnumAndModels()
    {
        $result = Authorize::using(AbilitiesEnum::ViewDashboard, 'App\Models\User');

        $this->assertSame(Authorize::class . ':view-dashboard,App\Models\User', $result);
    }

    public function testUsingWithUnitEnum()
    {
        $result = Authorize::using(AuthorizeMiddlewareTestUnitEnum::ManageUsers);

        $this->assertSame(Authorize::class . ':ManageUsers', $result);
    }

    public function testUsingWithUnitEnumAndModels()
    {
        $result = Authorize::using(AuthorizeMiddlewareTestUnitEnum::ViewReports, 'App\Models\Report');

        $this->assertSame(Authorize::class . ':ViewReports,App\Models\Report', $result);
    }

    public function testUsingWithIntBackedEnum()
    {
        $result = Authorize::using(AuthorizeMiddlewareTestIntBackedEnum::CreatePost);

        $this->assertSame(Authorize::class . ':1', $result);
    }

    public function testUsingWithStringAbilityAndMultipleModels()
    {
        $result = Authorize::using('transfer', 'App\Models\Account', 'App\Models\User');

        $this->assertSame(Authorize::class . ':transfer,App\Models\Account,App\Models\User', $result);
    }

    public function testSimpleAbilityUnauthorized()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $this->gate()->define('view-dashboard', function ($user, $additional = null) {
            $this->assertNull($additional);

            return false;
        });

        $this->router->get('dashboard', [
            'middleware' => Authorize::class . ':view-dashboard',
            'uses' => function () {
                return 'success';
            },
        ]);

        $this->router->dispatch(Request::create('dashboard', 'GET'));
    }

    public function testSimpleAbilityAuthorized()
    {
        $this->gate()->define('view-dashboard', function ($user) {
            return true;
        });

        $this->router->get('dashboard', [
            'middleware' => Authorize::class . ':view-dashboard',
            'uses' => function () {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithStringParameter()
    {
        $this->gate()->define('view-dashboard', function ($user, $param) {
            return $param === 'some string';
        });

        $this->router->get('dashboard', [
            'middleware' => Authorize::class . ':view-dashboard,"some string"',
            'uses' => function () {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithBackedEnumParameter()
    {
        $this->gate()->define('view-dashboard', function ($user) {
            return true;
        });

        $this->router->middleware(Authorize::using(AbilitiesEnum::ViewDashboard))->get('dashboard', [
            'uses' => function () {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithNullParameter()
    {
        $this->gate()->define('view-dashboard', function ($user, $param = null) {
            $this->assertNull($param);

            return true;
        });

        $this->router->get('dashboard', [
            'middleware' => Authorize::class . ':view-dashboard,null',
            'uses' => function () {
                return 'success';
            },
        ]);

        $this->router->dispatch(Request::create('dashboard', 'GET'));
    }

    public function testSimpleAbilityWithOptionalParameter()
    {
        $post = new stdClass();

        $this->router->bind('post', function () use ($post) {
            return $post;
        });

        $this->gate()->define('view-comments', function ($user, $model = null) {
            return true;
        });

        $middleware = [SubstituteBindings::class, Authorize::class . ':view-comments,post'];

        $this->router->get('comments', [
            'middleware' => $middleware,
            'uses' => function () {
                return 'success';
            },
        ]);
        $this->router->get('posts/{post}/comments', [
            'middleware' => $middleware,
            'uses' => function () {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('posts/1/comments', 'GET'));
        $this->assertSame('success', $response->content());

        $response = $this->router->dispatch(Request::create('comments', 'GET'));
        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithStringParameterFromRouteParameter()
    {
        $this->gate()->define('view-dashboard', function ($user, $param) {
            return $param === 'true';
        });

        $this->router->get('dashboard/{route_parameter}', [
            'middleware' => Authorize::class . ':view-dashboard,route_parameter',
            'uses' => function () {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard/true', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testSimpleAbilityWithStringParameter0FromRouteParameter()
    {
        $this->gate()->define('view-dashboard', function ($user, $param) {
            return $param === '0';
        });

        $this->router->get('dashboard/{route_parameter}', [
            'middleware' => Authorize::class . ':view-dashboard,route_parameter',
            'uses' => function () {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('dashboard/0', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testModelTypeUnauthorized()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $this->gate()->define('create', function ($user, $model) {
            $this->assertSame('App\User', $model);

            return false;
        });

        $this->router->get('users/create', [
            'middleware' => [SubstituteBindings::class, Authorize::class . ':create,App\User'],
            'uses' => function () {
                return 'success';
            },
        ]);

        $this->router->dispatch(Request::create('users/create', 'GET'));
    }

    public function testModelTypeAuthorized()
    {
        $this->gate()->define('create', function ($user, $model) {
            $this->assertSame('App\User', $model);

            return true;
        });

        $this->router->get('users/create', [
            'middleware' => Authorize::class . ':create,App\User',
            'uses' => function () {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('users/create', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testModelUnauthorized()
    {
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('This action is unauthorized.');

        $post = new stdClass();

        $this->router->bind('post', function () use ($post) {
            return $post;
        });

        $this->gate()->define('edit', function ($user, $model) use ($post) {
            $this->assertSame($model, $post);

            return false;
        });

        $this->router->get('posts/{post}/edit', [
            'middleware' => [SubstituteBindings::class, Authorize::class . ':edit,post'],
            'uses' => function () {
                return 'success';
            },
        ]);

        $this->router->dispatch(Request::create('posts/1/edit', 'GET'));
    }

    public function testModelAuthorized()
    {
        $post = new stdClass();

        $this->router->bind('post', function () use ($post) {
            return $post;
        });

        $this->gate()->define('edit', function ($user, $model) use ($post) {
            $this->assertSame($model, $post);

            return true;
        });

        $this->router->get('posts/{post}/edit', [
            'middleware' => [SubstituteBindings::class, Authorize::class . ':edit,post'],
            'uses' => function () {
                return 'success';
            },
        ]);

        $response = $this->router->dispatch(Request::create('posts/1/edit', 'GET'));

        $this->assertSame('success', $response->content());
    }

    public function testModelInstanceAsParameter()
    {
        $instance = m::mock(Model::class);

        $this->gate()->define('success', function ($user, $model) use ($instance) {
            $this->assertSame($model, $instance);

            return true;
        });

        $request = m::mock(Request::class);

        $next = function () {
            return new \Symfony\Component\HttpFoundation\Response();
        };

        (new Authorize($this->gate()))
            ->handle($request, $next, 'success', $instance);
    }

    /**
     * Get the Gate instance from the container.
     */
    protected function gate()
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
