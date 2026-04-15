<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Integrations;

use Exception;
use Hypervel\Routing\Router;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Tests\Testbench\TestCase;
use Override;
use PHPUnit\Framework\Attributes\Test;
use Workbench\App\Http\Controllers\ExampleController;

#[WithConfig('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class RouteTest extends TestCase
{
    #[Override]
    protected function defineRoutes(Router $router): void
    {
        $router->middleware('web')->get('web/test', fn () => 'Test using web');
        $router->middleware('api')->get('api/test', fn () => 'Test using api');

        $router->domain('api.localhost')
            ->group(function (Router $router) {
                $router->get('hello', fn () => 'hello from api');
            });

        $router->get('hello', ['as' => 'hi', 'uses' => fn () => 'hello world']);

        $router->get('goodbye', fn () => 'goodbye world')->name('bye');

        $router->group(['prefix' => 'boss'], function (Router $router) {
            $router->get('hello', ['as' => 'boss.hi', 'uses' => fn () => 'hello boss']);

            $router->get('goodbye', fn () => 'goodbye boss')->name('boss.bye');
        });

        $router->resource('foo', ExampleController::class);
    }

    #[Test]
    public function itCanResolveWebGroupRoute()
    {
        $crawler = $this->call('GET', 'web/test');

        $this->assertEquals('Test using web', $crawler->getContent());
    }

    #[Test]
    public function itCanResolveApiGroupRoute()
    {
        $crawler = $this->call('GET', 'api/test');

        $this->assertEquals('Test using api', $crawler->getContent());
    }

    #[Test]
    public function itCanResolveGetRoutes()
    {
        $crawler = $this->call('GET', 'hello');

        $this->assertEquals('hello world', $crawler->getContent());

        $crawler = $this->call('GET', 'goodbye');

        $this->assertEquals('goodbye world', $crawler->getContent());
    }

    #[Test]
    public function itCanResolveGetRoutesWithPrefixes()
    {
        $crawler = $this->call('GET', 'boss/hello');

        $this->assertEquals('hello boss', $crawler->getContent());

        $crawler = $this->call('GET', 'boss/goodbye');

        $this->assertEquals('goodbye boss', $crawler->getContent());
    }

    #[Test]
    public function itCanResolveResourceController()
    {
        $response = $this->call('GET', 'foo');

        $response->assertStatus(200);
        $this->assertEquals('ExampleController@index', $response->getContent());
    }

    #[Test]
    public function itCanResolveDomainRoute()
    {
        $response = $this->get('http://api.localhost/hello');

        $response->assertStatus(200);
        $this->assertEquals('hello from api', $response->getContent());
    }

    #[Test]
    public function itCanResolveNameRoutes()
    {
        $this->app['router']->get('passthrough', fn () => route('bye'))->name('pass');

        $response = $this->call('GET', route('pass'));

        $response->assertStatus(200);
        $this->assertEquals('http://localhost/goodbye', $response->getContent());
    }

    #[Test]
    public function itCanHandleRouteThrowingException()
    {
        $this->app['router']->get('bad-route', fn () => throw new Exception('Route error!'))->name('bad');

        $response = $this->call('GET', route('bad'));

        $response->assertStatus(500);
    }
}
