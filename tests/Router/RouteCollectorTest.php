<?php

declare(strict_types=1);

namespace Hypervel\Tests\Router;

use FastRoute\DataGenerator\GroupCountBased as DataGenerator;
use FastRoute\RouteParser\Std;
use Hyperf\HttpServer\MiddlewareManager;
use Hypervel\Router\RouteCollector;
use Hypervel\Tests\Router\Stub\RouteCollectorStub;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class RouteCollectorTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();

        MiddlewareManager::$container = [];
    }

    public function testAddRoute()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $collector->get('/', 'Handler::Get');
        $collector->post('/', 'Handler::Post');
        $collector->addGroup('/api', function ($collector) {
            $collector->get('/', 'Handler::ApiGet');
            $collector->post('/', 'Handler::ApiPost');
        });

        $data = $collector->getData()[0];
        $this->assertSame('Handler::Get', $data['GET']['/']->callback);
        $this->assertSame('Handler::ApiGet', $data['GET']['/api']->callback);
        $this->assertSame('Handler::Post', $data['POST']['/']->callback);
        $this->assertSame('Handler::ApiPost', $data['POST']['/api']->callback);
    }

    public function testGetRouteParser()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $this->assertSame($parser, $collector->getRouteParser());
    }

    public function testAddGroupMiddleware()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $collector->get('/', 'Handler::Get', [
            'middleware' => ['GetMiddleware'],
        ]);
        $collector->addGroup('/api', function ($collector) {
            $collector->get('/', 'Handler::ApiGet', [
                'middleware' => ['ApiSelfGetMiddleware'],
            ]);
        }, [
            'middleware' => ['ApiGetMiddleware'],
        ]);
        $collector->post('/', 'Handler::Post', [
            'middleware' => ['PostMiddleware'],
        ]);
        $collector->post('/user/{id:\d+}', 'Handler::Post', [
            'middleware' => ['PostMiddleware'],
        ]);

        $data = $collector->getData()[0];
        $this->assertSame('Handler::Get', $data['GET']['/']->callback);
        $this->assertSame('Handler::ApiGet', $data['GET']['/api']->callback);
        $this->assertSame('Handler::Post', $data['POST']['/']->callback);
        $this->assertSame(['middleware' => ['PostMiddleware']], $data['POST']['/']->options);

        $middle = MiddlewareManager::$container;
        $this->assertSame(['GetMiddleware'], $middle['http']['/']['GET']);
        $this->assertSame(['PostMiddleware'], $middle['http']['/']['POST']);
        $this->assertSame(['ApiGetMiddleware', 'ApiSelfGetMiddleware'], $middle['http']['/api']['GET']);
        $this->assertSame(['PostMiddleware'], $middle['http']['/user/{id:\d+}']['POST']);
    }

    public function testAddGroupMiddlewareFromAnotherServer()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator, 'test');

        $collector->addGroup('/api', function ($collector) {
            $collector->get('/', 'Handler::ApiGet', [
                'middleware' => ['ApiSelfGetMiddleware'],
            ]);
        }, [
            'middleware' => ['ApiGetMiddleware'],
        ]);

        $middle = MiddlewareManager::$container;
        $this->assertSame(['ApiGetMiddleware', 'ApiSelfGetMiddleware'], $middle['test']['/api']['GET']);
    }

    public function testAddWithoutMiddleware()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $collector->get('/', 'Handler::Get', [
            'middleware' => ['GetMiddleware', 'PostMiddleware'],
            'without_middleware' => ['PostMiddleware'],
        ]);
        $collector->addGroup('/api', function ($collector) {
            $collector->get('/', 'Handler::ApiGet', [
                'middleware' => ['ApiSelfGetMiddleware'],
                'without_middleware' => ['ApiGetMiddleware'],
            ]);
            $collector->get('/foo', 'Handler::ApiGet', [
                'middleware' => ['FooGetMiddleware', 'BarGetMiddleware'],
            ]);
        }, [
            'middleware' => ['ApiGetMiddleware'],
            'without_middleware' => ['FooGetMiddleware'],
        ]);

        $middleware = MiddlewareManager::$container['http'];

        $this->assertSame(['GetMiddleware'], $middleware['/']['GET']);
        $this->assertSame(['ApiSelfGetMiddleware'], $middleware['/api']['GET']);
        $this->assertSame(['ApiGetMiddleware', 'BarGetMiddleware'], $middleware['/api/foo']['GET']);
    }

    public function testRouterCollectorMergeOptions()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollectorStub($parser, $generator, 'test');

        $origin = [
            'middleware' => ['A', 'B'],
        ];
        $options = [
            'middleware' => ['C', 'B'],
        ];

        $res = $collector->mergeOptions($origin, $options);
        $this->assertSame(['A', 'B', 'C', 'B'], $res['middleware']);
    }

    public function testMiddlewareInOptionalRoute()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollectorStub($parser, $generator, 'test');

        $routes = [
            '/user/[{id:\d+}]',
            '/role/{id:\d+}',
            '/user',
        ];

        foreach ($routes as $route) {
            $collector->addRoute('GET', $route, 'User::Info', ['middleware' => $middlewares = ['FooMiddleware']]);
            $this->assertSame($middlewares, MiddlewareManager::get('test', $route, 'GET'));
        }
    }

    public function testNamedRoute()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $collector->get('/', 'Handler::Get', ['as' => 'get']);
        $collector->post('/', 'Handler::Post', ['as' => 'post']);
        $collector->addGroup('/api', function ($collector) {
            $collector->get('/', 'Handler::ApiGet', ['as' => 'api-get']);
            $collector->post('/', 'Handler::ApiPost', ['as' => 'api-post']);
        });

        $collector->get('/foo/{bar}', 'Handler::Params', ['as' => 'params']);
        $collector->get('/foo/{bar}/{baz:[0-9]+}', 'Handler::Regex', ['as' => 'regex']);

        $namedRoutes = $collector->getNamedRoutes();

        $this->assertSame(['/'], $namedRoutes['get']);
        $this->assertSame(['/'], $namedRoutes['post']);
        $this->assertSame(['/api'], $namedRoutes['api-get']);
        $this->assertSame(['/api'], $namedRoutes['api-post']);
        $this->assertSame(['/foo/', ['bar', '[^/]+']], $namedRoutes['params']);
        $this->assertSame(['/foo/', ['bar', '[^/]+'], '/', ['baz', '[0-9]+']], $namedRoutes['regex']);
    }

    public function testNamedRouteWithGroup()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $collector->addGroup('/foo', function ($collector) {
            $collector->get('/bar', 'Handler::Bar', ['as' => 'bar']);
            $collector->addGroup('/baz', function ($collector) {
                $collector->get('/boom', 'Handler::Boom', ['as' => 'boom']);
            }, ['as' => 'baz']);
        }, ['as' => 'foo']);

        $namedRoutes = $collector->getNamedRoutes();

        $this->assertSame(['/foo/bar'], $namedRoutes['foo.bar']);
        $this->assertSame(['/foo/baz/boom'], $namedRoutes['foo.baz.boom']);
    }

    public function testNamespaceRouteWithGroup()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $collector->addGroup('/foo', function ($collector) {
            $collector->get('/bar', 'Handler::Bar', ['as' => 'bar']);
            $collector->addGroup('/baz', function ($collector) {
                $collector->get('/boom', 'Handler::Boom', ['as' => 'boom']);
            }, ['as' => 'baz']);
        }, ['namespace' => 'Foo']);

        $data = $collector->getData()[0]['GET'];

        $this->assertSame('Foo\Handler::Bar', $data['/foo/bar']->callback);
        $this->assertSame('Foo\Handler::Boom', $data['/foo/baz/boom']->callback);
    }

    public function testHandlerInOptions()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $collector->get('/', [
            'as' => 'get',
            'uses' => 'Handler::Get',
        ]);

        $data = $collector->getData()[0];
        $this->assertSame('Handler::Get', $data['GET']['/']->callback);

        $namedRoutes = $collector->getNamedRoutes();
        $this->assertSame(['/'], $namedRoutes['get']);
    }

    public function testClosureHandlerInOptions()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $collector->get('/', [
            'as' => 'get',
            $action = function () {},
        ]);

        $data = $collector->getData()[0];
        $this->assertSame($action, $data['GET']['/']->callback);

        $namedRoutes = $collector->getNamedRoutes();
        $this->assertSame(['/'], $namedRoutes['get']);
    }

    public function testCallableHandler()
    {
        $parser = new Std();
        $generator = new DataGenerator();
        $collector = new RouteCollector($parser, $generator);

        $collector->get('/', ['class', 'method']);

        $data = $collector->getData()[0];
        $this->assertSame(['class', 'method'], $data['GET']['/']->callback);
    }
}
