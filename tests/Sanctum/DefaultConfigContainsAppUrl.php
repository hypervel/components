<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Hypervel\Sanctum\Sanctum;
use Hypervel\Testbench\TestCase;
use Mockery;

/**
 * @internal
 * @coversNothing
 */
class DefaultConfigContainsAppUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        parent::tearDown();
        
        Mockery::close();
    }

    public function testDefaultConfigContainsAppUrl(): void
    {
        putenv('APP_URL=https://www.example.com');
        $this->app->get(\Hyperf\Contract\ConfigInterface::class)->set('app.url', 'https://www.example.com');

        $config = require __DIR__ . '/../../src/sanctum/publish/sanctum.php';

        $app_host = parse_url(env('APP_URL'), PHP_URL_HOST);

        $this->assertContains($app_host, $config['stateful']);
    }

    public function testAppUrlIsNotParsedWhenMissingFromEnv(): void
    {
        putenv('APP_URL');
        $this->app->get(\Hyperf\Contract\ConfigInterface::class)->set('app.url', null);

        $config = require __DIR__ . '/../../src/sanctum/publish/sanctum.php';

        $this->assertNull(env('APP_URL'));
        $this->assertNotContains('', $config['stateful']);

        putenv('APP_URL=https://www.example.com');
        $this->app->get(\Hyperf\Contract\ConfigInterface::class)->set('app.url', 'https://www.example.com');
    }

    public function testRequestFromAppUrlIsStatefulWithDefaultConfig(): void
    {
        $this->app->get(\Hyperf\Contract\ConfigInterface::class)->set('app.url', 'https://www.example.com');
        $this->app->get(\Hyperf\Contract\ConfigInterface::class)->set('sanctum.stateful', explode(',', sprintf(
            '%s%s',
            'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
            ',www.example.com'
        )));

        $request = Mockery::mock(RequestInterface::class);
        $request->shouldReceive('header')
            ->with('referer')
            ->andReturn('https://www.example.com');
        $request->shouldReceive('header')
            ->with('origin')
            ->andReturn(null);

        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend($request));
    }

    public function testCurrentApplicationUrlWithPort(): void
    {
        $this->app->get(\Hyperf\Contract\ConfigInterface::class)->set('app.url', 'https://www.example.com:8080');
        
        $result = Sanctum::currentApplicationUrlWithPort();
        
        $this->assertEquals(',www.example.com:8080', $result);
    }

    public function testCurrentApplicationUrlWithoutPort(): void
    {
        $this->app->get(\Hyperf\Contract\ConfigInterface::class)->set('app.url', 'https://www.example.com');
        
        $result = Sanctum::currentApplicationUrlWithPort();
        
        $this->assertEquals(',www.example.com', $result);
    }

    public function testCurrentApplicationUrlWhenNotSet(): void
    {
        $this->app->get(\Hyperf\Contract\ConfigInterface::class)->set('app.url', null);
        
        $result = Sanctum::currentApplicationUrlWithPort();
        
        $this->assertEquals('', $result);
    }
}