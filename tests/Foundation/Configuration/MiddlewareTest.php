<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Configuration;

use Hypervel\Container\Container;
use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Contracts\Foundation\MaintenanceMode;
use Hypervel\Cookie\Middleware\EncryptCookies;
use Hypervel\Foundation\Configuration\Middleware;
use Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Hypervel\Foundation\Http\Middleware\PreventRequestForgery;
use Hypervel\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Hypervel\Foundation\Http\Middleware\TrimStrings;
use Hypervel\Http\Middleware\TrustHosts;
use Hypervel\Http\Middleware\TrustProxies;
use Hypervel\Http\Request;
use Hypervel\Tests\TestCase;
use Mockery as m;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * @internal
 * @coversNothing
 */
class MiddlewareTest extends TestCase
{
    protected function tearDown(): void
    {
        Container::setInstance(null);
        ConvertEmptyStringsToNull::flushState();
        EncryptCookies::flushState();
        PreventRequestForgery::flushState();
        PreventRequestsDuringMaintenance::flushState();
        TrimStrings::flushState();
        TrustProxies::flushState();

        parent::tearDown();
    }

    public function testConvertEmptyStringsToNull()
    {
        $configuration = new Middleware();
        $middleware = new ConvertEmptyStringsToNull();

        $configuration->convertEmptyStringsToNull(except: [
            fn (Request $request) => $request->has('skip-all-1'),
            fn (Request $request) => $request->has('skip-all-2'),
        ]);

        $symfonyRequest = new SymfonyRequest([
            'aaa' => '  123  ',
            'bbb' => '',
        ]);

        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $request = $middleware->handle($request, fn (Request $request) => $request);

        $this->assertSame('  123  ', $request->get('aaa'));
        $this->assertNull($request->get('bbb'));

        $symfonyRequest = new SymfonyRequest([
            'aaa' => '  123  ',
            'bbb' => '',
            'skip-all-1' => 'true',
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $request = $middleware->handle($request, fn (Request $request) => $request);

        $this->assertSame('  123  ', $request->get('aaa'));
        $this->assertSame('', $request->get('bbb'));

        $symfonyRequest = new SymfonyRequest([
            'aaa' => '  123  ',
            'bbb' => '',
            'skip-all-2' => 'true',
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $request = $middleware->handle($request, fn (Request $request) => $request);

        $this->assertSame('  123  ', $request->get('aaa'));
        $this->assertSame('', $request->get('bbb'));
    }

    public function testTrimStrings()
    {
        $configuration = new Middleware();
        $middleware = new TrimStrings();

        $configuration->trimStrings(except: [
            'aaa',
            fn (Request $request) => $request->has('skip-all'),
        ]);

        $symfonyRequest = new SymfonyRequest([
            'aaa' => '  123  ',
            'bbb' => '  456  ',
            'ccc' => '  789  ',
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $request = $middleware->handle($request, fn (Request $request) => $request);

        $this->assertSame('  123  ', $request->get('aaa'));
        $this->assertSame('456', $request->get('bbb'));
        $this->assertSame('789', $request->get('ccc'));

        $symfonyRequest = new SymfonyRequest([
            'aaa' => '  123  ',
            'bbb' => '  456  ',
            'ccc' => '  789  ',
            'skip-all' => true,
        ]);
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $request = Request::createFromBase($symfonyRequest);

        $request = $middleware->handle($request, fn (Request $request) => $request);

        $this->assertSame('  123  ', $request->get('aaa'));
        $this->assertSame('  456  ', $request->get('bbb'));
        $this->assertSame('  789  ', $request->get('ccc'));
    }

    public function testTrustProxies()
    {
        $configuration = new Middleware();
        $middleware = new TrustProxies();

        $reflection = new ReflectionClass($middleware);
        $method = $reflection->getMethod('proxies');
        $property = $reflection->getProperty('proxies');

        $this->assertNull($method->invoke($middleware));

        $property->setValue($middleware, [
            '192.168.1.1',
            '192.168.1.2',
        ]);

        $this->assertEquals([
            '192.168.1.1',
            '192.168.1.2',
        ], $method->invoke($middleware));

        $configuration->trustProxies(at: '*');
        $this->assertEquals('*', $method->invoke($middleware));

        $configuration->trustProxies(at: [
            '192.168.1.3',
            '192.168.1.4',
        ]);
        $this->assertEquals([
            '192.168.1.3',
            '192.168.1.4',
        ], $method->invoke($middleware));
    }

    public function testTrustHeaders()
    {
        $configuration = new Middleware();
        $middleware = new TrustProxies();

        $reflection = new ReflectionClass($middleware);
        $method = $reflection->getMethod('headers');
        $property = $reflection->getProperty('headers');

        $this->assertEquals(Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO
            | Request::HEADER_X_FORWARDED_PREFIX
            | Request::HEADER_X_FORWARDED_AWS_ELB, $method->invoke($middleware));

        $property->setValue($middleware, Request::HEADER_X_FORWARDED_AWS_ELB);

        $this->assertEquals(Request::HEADER_X_FORWARDED_AWS_ELB, $method->invoke($middleware));

        $configuration->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR);

        $this->assertEquals(Request::HEADER_X_FORWARDED_FOR, $method->invoke($middleware));

        $configuration->trustProxies(
            [
                '192.168.1.3',
                '192.168.1.4',
            ],
            Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
        );

        $this->assertEquals(Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT, $method->invoke($middleware));
    }

    public function testTrustHosts()
    {
        $app = m::mock(Application::class);
        $configuration = new Middleware();
        $middleware = new class($app) extends TrustHosts {
            protected function allSubdomainsOfApplicationUrl(): ?string
            {
                return '^(.+\.)?laravel\.test$';
            }
        };

        $this->assertEquals(['^(.+\.)?laravel\.test$'], $middleware->hosts());

        $configuration->trustHosts();
        $this->assertEquals(['^(.+\.)?laravel\.test$'], $middleware->hosts());

        $configuration->trustHosts(at: ['my.test']);
        $this->assertEquals(['my.test', '^(.+\.)?laravel\.test$'], $middleware->hosts());

        $configuration->trustHosts(at: static fn () => ['my.test']);
        $this->assertEquals(['my.test', '^(.+\.)?laravel\.test$'], $middleware->hosts());

        $configuration->trustHosts(at: ['my.test'], subdomains: false);
        $this->assertEquals(['my.test'], $middleware->hosts());

        $configuration->trustHosts(at: static fn () => ['my.test'], subdomains: false);
        $this->assertEquals(['my.test'], $middleware->hosts());

        $configuration->trustHosts(at: []);
        $this->assertEquals(['^(.+\.)?laravel\.test$'], $middleware->hosts());

        $configuration->trustHosts(at: static fn () => []);
        $this->assertEquals(['^(.+\.)?laravel\.test$'], $middleware->hosts());

        $configuration->trustHosts(at: [], subdomains: false);
        $this->assertEquals([], $middleware->hosts());

        $configuration->trustHosts(at: static fn () => [], subdomains: false);
        $this->assertEquals([], $middleware->hosts());
    }

    public function testEncryptCookies()
    {
        $configuration = new Middleware();
        $encrypter = m::mock(Encrypter::class);
        $middleware = new EncryptCookies($encrypter);

        $this->assertFalse($middleware->isDisabled('aaa'));
        $this->assertFalse($middleware->isDisabled('bbb'));

        $configuration->encryptCookies(except: [
            'aaa',
            'bbb',
        ]);

        $this->assertTrue($middleware->isDisabled('aaa'));
        $this->assertTrue($middleware->isDisabled('bbb'));
    }

    public function testPreventRequestsDuringMaintenance()
    {
        $configuration = new Middleware();

        $mode = m::mock(MaintenanceMode::class);
        $mode->shouldReceive('active')->andReturn(true);
        $mode->shouldReceive('data')->andReturn([]);
        $app = m::mock(Application::class);
        $app->shouldReceive('maintenanceMode')->andReturn($mode);
        $middleware = new PreventRequestsDuringMaintenance($app);

        $reflection = new ReflectionClass($middleware);
        $method = $reflection->getMethod('inExceptArray');

        $symfonyRequest = new SymfonyRequest();
        $symfonyRequest->server->set('REQUEST_METHOD', 'GET');
        $symfonyRequest->server->set('REQUEST_URI', 'metrics/requests');

        $request = Request::createFromBase($symfonyRequest);
        $this->assertFalse($method->invoke($middleware, $request));

        $configuration->preventRequestsDuringMaintenance(['metrics/*']);
        $this->assertTrue($method->invoke($middleware, $request));
    }

    public function testPreventRequestForgery()
    {
        $configuration = new Middleware();
        $middleware = new PreventRequestForgery(
            m::mock(Application::class),
            m::mock(Encrypter::class)
        );

        $this->assertSame([], $middleware->getExcludedPaths());

        $configuration->preventRequestForgery(
            except: ['/webhook', '/api/*'],
            originOnly: true,
            allowSameSite: true
        );

        $this->assertSame(['/webhook', '/api/*'], $middleware->getExcludedPaths());

        $reflection = new ReflectionClass(PreventRequestForgery::class);
        $this->assertTrue($reflection->getStaticPropertyValue('originOnly'));
        $this->assertTrue($reflection->getStaticPropertyValue('allowSameSite'));
    }

    public function testDefaultGlobalMiddleware()
    {
        $middleware = new Middleware();

        $this->assertSame([
            \Hypervel\Http\Middleware\ValidatePathEncoding::class,
            \Hypervel\Http\Middleware\TrustProxies::class,
            \Hypervel\Http\Middleware\HandleCors::class,
            \Hypervel\Http\Middleware\ValidatePostSize::class,
            \Hypervel\Foundation\Http\Middleware\TrimStrings::class,
            \Hypervel\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ], $middleware->getGlobalMiddleware());
    }

    public function testDefaultWebMiddlewareGroup()
    {
        $middleware = new Middleware();
        $groups = $middleware->getMiddlewareGroups();

        $this->assertSame([
            \Hypervel\Cookie\Middleware\EncryptCookies::class,
            \Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Hypervel\Session\Middleware\StartSession::class,
            \Hypervel\View\Middleware\ShareErrorsFromSession::class,
            \Hypervel\Foundation\Http\Middleware\PreventRequestForgery::class,
            \Hypervel\Routing\Middleware\SubstituteBindings::class,
        ], $groups['web']);
    }

    public function testDefaultApiMiddlewareGroup()
    {
        $middleware = new Middleware();
        $groups = $middleware->getMiddlewareGroups();

        $this->assertSame([
            \Hypervel\Routing\Middleware\SubstituteBindings::class,
        ], $groups['api']);
    }

    public function testDefaultMiddlewareAliases()
    {
        $middleware = new Middleware();

        $this->assertSame([
            'auth' => \Hypervel\Auth\Middleware\Authenticate::class,
            'auth.session' => \Hypervel\Session\Middleware\AuthenticateSession::class,
            'cache.headers' => \Hypervel\Http\Middleware\SetCacheHeaders::class,
            'can' => \Hypervel\Auth\Middleware\Authorize::class,
            'precognitive' => \Hypervel\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            'signed' => \Hypervel\Routing\Middleware\ValidateSignature::class,
            'throttle' => \Hypervel\Routing\Middleware\ThrottleRequests::class,
        ], $middleware->getMiddlewareAliases());
    }

    public function testStatefulApiAddsEnsureFrontendRequestsAreStateful()
    {
        $middleware = new Middleware();
        $middleware->statefulApi();

        $groups = $middleware->getMiddlewareGroups();

        $this->assertSame(
            \Hypervel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            $groups['api'][0]
        );
        $this->assertContains(
            \Hypervel\Routing\Middleware\SubstituteBindings::class,
            $groups['api']
        );
    }

    public function testDefaultMiddlewarePriority()
    {
        $kernel = new \Hypervel\Foundation\Http\Kernel(
            m::mock(Application::class),
            m::mock(\Hypervel\Routing\Router::class)
        );

        $reflection = new ReflectionClass($kernel);
        $property = $reflection->getProperty('middlewarePriority');
        $priority = $property->getValue($kernel);

        $this->assertSame([
            \Hypervel\Foundation\Http\Middleware\HandlePrecognitiveRequests::class,
            \Hypervel\Cookie\Middleware\EncryptCookies::class,
            \Hypervel\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Hypervel\Session\Middleware\StartSession::class,
            \Hypervel\View\Middleware\ShareErrorsFromSession::class,
            \Hypervel\Contracts\Auth\Middleware\AuthenticatesRequests::class,
            \Hypervel\Routing\Middleware\ThrottleRequests::class,
            \Hypervel\Routing\Middleware\ThrottleRequestsWithRedis::class,
            \Hypervel\Contracts\Session\Middleware\AuthenticatesSessions::class,
            \Hypervel\Routing\Middleware\SubstituteBindings::class,
            \Hypervel\Auth\Middleware\Authorize::class,
        ], $priority);
    }

    public function testWithMiddlewareAppliesPriorityToKernel()
    {
        $kernel = new \Hypervel\Foundation\Http\Kernel(
            m::mock(Application::class),
            m::mock(\Hypervel\Routing\Router::class)->shouldIgnoreMissing()
        );

        $middleware = new Middleware();
        $middleware->priority([
            'FirstMiddleware',
            'SecondMiddleware',
            'ThirdMiddleware',
        ]);

        $kernel->setMiddlewarePriority($middleware->getMiddlewarePriority());

        $reflection = new ReflectionClass($kernel);
        $property = $reflection->getProperty('middlewarePriority');

        $this->assertSame([
            'FirstMiddleware',
            'SecondMiddleware',
            'ThirdMiddleware',
        ], $property->getValue($kernel));
    }

    public function testWithMiddlewareAppliesAppendsToPriority()
    {
        $kernel = new \Hypervel\Foundation\Http\Kernel(
            m::mock(Application::class),
            m::mock(\Hypervel\Routing\Router::class)->shouldIgnoreMissing()
        );

        $middleware = new Middleware();
        $middleware->appendToPriorityList(
            \Hypervel\Routing\Middleware\SubstituteBindings::class,
            'AppendedMiddleware'
        );

        foreach ($middleware->getMiddlewarePriorityAppends() as $newMiddleware => $after) {
            $kernel->addToMiddlewarePriorityAfter($after, $newMiddleware);
        }

        $reflection = new ReflectionClass($kernel);
        $priority = $reflection->getProperty('middlewarePriority')->getValue($kernel);

        $bindingsIndex = array_search(\Hypervel\Routing\Middleware\SubstituteBindings::class, $priority);
        $appendedIndex = array_search('AppendedMiddleware', $priority);

        $this->assertNotFalse($appendedIndex, 'Appended middleware should be in the priority list');
        $this->assertSame($bindingsIndex + 1, $appendedIndex, 'Appended middleware should be immediately after SubstituteBindings');
    }

    public function testWithMiddlewareAppliesPrependsToPriority()
    {
        $kernel = new \Hypervel\Foundation\Http\Kernel(
            m::mock(Application::class),
            m::mock(\Hypervel\Routing\Router::class)->shouldIgnoreMissing()
        );

        $middleware = new Middleware();
        $middleware->prependToPriorityList(
            \Hypervel\Routing\Middleware\SubstituteBindings::class,
            'PrependedMiddleware'
        );

        foreach ($middleware->getMiddlewarePriorityPrepends() as $newMiddleware => $before) {
            $kernel->addToMiddlewarePriorityBefore($before, $newMiddleware);
        }

        $reflection = new ReflectionClass($kernel);
        $priority = $reflection->getProperty('middlewarePriority')->getValue($kernel);

        $bindingsIndex = array_search(\Hypervel\Routing\Middleware\SubstituteBindings::class, $priority);
        $prependedIndex = array_search('PrependedMiddleware', $priority);

        $this->assertNotFalse($prependedIndex, 'Prepended middleware should be in the priority list');
        $this->assertSame($bindingsIndex - 1, $prependedIndex, 'Prepended middleware should be immediately before SubstituteBindings');
    }

    public function testWithMiddlewareAppliesGlobalGroupsAndAliasesToKernel()
    {
        $kernel = new \Hypervel\Foundation\Http\Kernel(
            m::mock(Application::class),
            m::mock(\Hypervel\Routing\Router::class)->shouldIgnoreMissing()
        );

        $middleware = new Middleware();
        $middleware->use(['CustomGlobalMiddleware']);
        $middleware->group('custom', ['CustomGroupMiddleware']);
        $middleware->alias(['custom-alias' => 'CustomAliasMiddleware']);

        $kernel->setGlobalMiddleware($middleware->getGlobalMiddleware());
        $kernel->setMiddlewareGroups($middleware->getMiddlewareGroups());
        $kernel->setMiddlewareAliases($middleware->getMiddlewareAliases());

        $reflection = new ReflectionClass($kernel);

        $this->assertSame(
            ['CustomGlobalMiddleware'],
            $reflection->getProperty('middleware')->getValue($kernel)
        );

        $groups = $reflection->getProperty('middlewareGroups')->getValue($kernel);
        $this->assertArrayHasKey('custom', $groups);
        $this->assertSame(['CustomGroupMiddleware'], $groups['custom']);

        $aliases = $reflection->getProperty('middlewareAliases')->getValue($kernel);
        $this->assertSame('CustomAliasMiddleware', $aliases['custom-alias']);
    }

    public function testWithMiddlewareWiresConfigThroughApplicationBuilder()
    {
        $app = \Hypervel\Foundation\Application::configure(
            basePath: __DIR__
        )->withMiddleware(function (Middleware $middleware) {
            $middleware->appendToPriorityList(
                \Hypervel\Routing\Middleware\SubstituteBindings::class,
                'AppendedViaBuilder'
            );
            $middleware->prependToPriorityList(
                \Hypervel\Cookie\Middleware\EncryptCookies::class,
                'PrependedViaBuilder'
            );
            $middleware->group('custom', ['CustomGroupMiddleware']);
            $middleware->alias(['custom-alias' => 'CustomAliasMiddleware']);
        })->create();

        $kernel = $app->make(\Hypervel\Contracts\Http\Kernel::class);

        $reflection = new ReflectionClass($kernel);
        $priority = $reflection->getProperty('middlewarePriority')->getValue($kernel);

        // Verify appended middleware is after SubstituteBindings
        $bindingsIndex = array_search(\Hypervel\Routing\Middleware\SubstituteBindings::class, $priority);
        $appendedIndex = array_search('AppendedViaBuilder', $priority);
        $this->assertNotFalse($appendedIndex, 'Appended middleware should be in the priority list');
        $this->assertSame($bindingsIndex + 1, $appendedIndex);

        // Verify prepended middleware is before EncryptCookies
        $encryptIndex = array_search(\Hypervel\Cookie\Middleware\EncryptCookies::class, $priority);
        $prependedIndex = array_search('PrependedViaBuilder', $priority);
        $this->assertNotFalse($prependedIndex, 'Prepended middleware should be in the priority list');
        $this->assertSame($encryptIndex - 1, $prependedIndex);

        // Verify custom group was applied
        $groups = $reflection->getProperty('middlewareGroups')->getValue($kernel);
        $this->assertArrayHasKey('custom', $groups);
        $this->assertSame(['CustomGroupMiddleware'], $groups['custom']);

        // Verify custom alias was applied
        $aliases = $reflection->getProperty('middlewareAliases')->getValue($kernel);
        $this->assertSame('CustomAliasMiddleware', $aliases['custom-alias']);
    }
}
