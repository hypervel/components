<?php

declare(strict_types=1);

namespace Hypervel\Tests\ApiClient;

use Hypervel\ApiClient\ApiClient;
use Hypervel\ApiClient\ApiRequest;
use Hypervel\ApiClient\ApiResponse;
use Hypervel\HttpClient\Request;
use Hypervel\Support\DataObject;
use Hypervel\Support\Facades\Http;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;

/**
 * @internal
 * @covers \Hypervel\ApiClient\PendingRequest
 */
class PendingRequestTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function testWithRequestMiddlewareSetsMiddleware(): void
    {
        $client = new ApiClient();
        $middleware = [TestRequestMiddleware::class];

        $pending = $client->withRequestMiddleware($middleware);

        Http::fake(['test' => Http::response('{"data": "test"}')]);
        $pending->get('test');

        $this->assertTrue(TestRequestMiddleware::$called);
        TestRequestMiddleware::reset();
    }

    public function testWithAddedRequestMiddlewareAppendsMiddleware(): void
    {
        $client = new ApiClient();
        $middlewareA = [TestRequestMiddleware::class];
        $middlewareB = [AnotherRequestMiddleware::class];

        $pending = $client
            ->withRequestMiddleware($middlewareA)
            ->withAddedRequestMiddleware($middlewareB);

        Http::fake(['test' => Http::response('{"data": "test"}')]);
        $pending->get('test');

        $this->assertTrue(TestRequestMiddleware::$called);
        $this->assertTrue(AnotherRequestMiddleware::$called);
        TestRequestMiddleware::reset();
        AnotherRequestMiddleware::reset();
    }

    public function testWithResponseMiddlewareSetsMiddleware(): void
    {
        $client = new ApiClient();
        $middleware = [TestResponseMiddleware::class];

        $pending = $client->withResponseMiddleware($middleware);

        Http::fake(['test' => Http::response('{"data": "test"}')]);
        $pending->get('test');

        $this->assertTrue(TestResponseMiddleware::$called);
        TestResponseMiddleware::reset();
    }

    public function testWithAddedResponseMiddlewareAppendsMiddleware(): void
    {
        $client = new ApiClient();
        $middlewareA = [TestResponseMiddleware::class];
        $middlewareB = [AnotherResponseMiddleware::class];

        $pending = $client
            ->withResponseMiddleware($middlewareA)
            ->withAddedResponseMiddleware($middlewareB);

        Http::fake(['test' => Http::response('{"data": "test"}')]);
        $pending->get('test');

        $this->assertTrue(TestResponseMiddleware::$called);
        $this->assertTrue(AnotherResponseMiddleware::$called);
        TestResponseMiddleware::reset();
        AnotherResponseMiddleware::reset();
    }

    public function testRequestMiddlewareCanModifyRequest(): void
    {
        $client = new ApiClient();

        $pending = $client->withRequestMiddleware([AddHeaderRequestMiddleware::class]);

        Http::fake(['test' => Http::response('{"success": true}')]);
        $pending->get('test');

        Http::assertSent(function (Request $request) {
            return $request->hasHeader('X-Custom-Header')
                && $request->header('X-Custom-Header')[0] === 'middleware-value';
        });
    }

    public function testResponseMiddlewareCanModifyResponse(): void
    {
        $client = new ApiClient();

        $pending = $client->withResponseMiddleware([AddHeaderResponseMiddleware::class]);

        Http::fake(['test' => Http::response('{"success": true}')]);
        $response = $pending->get('test');

        $this->assertTrue($response->hasHeader('X-Response-Header'));
        $this->assertEquals(
            'response-value',
            $response->header('X-Response-Header')
        );
    }

    public function testMiddlewareExecutionOrder(): void
    {
        $client = new ApiClient();

        OrderTrackingMiddleware::reset();

        $pending = $client->withRequestMiddleware([
            OrderTrackingMiddleware::class,
            SecondOrderTrackingMiddleware::class,
        ]);

        Http::fake(['test' => Http::response('{"success": true}')]);
        $pending->get('test');

        $this->assertEquals([1, 2], OrderTrackingMiddleware::$order);
        OrderTrackingMiddleware::reset();
    }

    public function testMiddlewareCanBeDisabled(): void
    {
        $client = new ApiClient();

        $pending = $client
            ->withRequestMiddleware([TestRequestMiddleware::class])
            ->disableMiddleware();

        Http::fake(['test' => Http::response('{"data": "test"}')]);
        $pending->get('test');

        $this->assertFalse(TestRequestMiddleware::$called);
        TestRequestMiddleware::reset();
    }

    public function testMiddlewareCanBeEnabled(): void
    {
        $client = new ApiClient();

        $pending = $client
            ->withRequestMiddleware([TestRequestMiddleware::class])
            ->disableMiddleware()
            ->enableMiddleware();

        Http::fake(['test' => Http::response('{"data": "test"}')]);
        $pending->get('test');

        $this->assertTrue(TestRequestMiddleware::$called);
        TestRequestMiddleware::reset();
    }

    public function testWithMiddlewareOptionsPassesOptionsToMiddleware(): void
    {
        $client = new ApiClient();
        $options = ['key' => 'value', 'timeout' => 30];

        $pending = $client
            ->withMiddlewareOptions($options)
            ->withRequestMiddleware([OptionsCheckingMiddleware::class]);

        Http::fake(['test' => Http::response('{"success": true}')]);
        $pending->get('test');

        $this->assertEquals($options, OptionsCheckingMiddleware::$receivedOptions);
        OptionsCheckingMiddleware::reset();
    }

    public function testMiddlewareCaching(): void
    {
        $client = new ApiClient();

        // First request creates middleware instance
        $pendingA = $client->withRequestMiddleware([CachingTestMiddleware::class]);
        Http::fake(['test1' => Http::response('{"data": "test1"}')]);
        $pendingA->get('test1');

        $firstInstanceId = CachingTestMiddleware::$instanceId;

        // Second request should reuse cached instance
        $pendingB = $client->withRequestMiddleware([CachingTestMiddleware::class]);
        Http::fake(['test2' => Http::response('{"data": "test2"}')]);
        $pendingB->get('test2');

        $this->assertEquals($firstInstanceId, CachingTestMiddleware::$instanceId);
        CachingTestMiddleware::reset();
    }

    public function testFlushCacheClearsMiddlewareCache(): void
    {
        $client = new ApiClient();

        // First request creates middleware instance
        $pendingA = $client->withRequestMiddleware([CachingTestMiddleware::class]);
        Http::fake(['test1' => Http::response('{"data": "test1"}')]);
        $pendingA->get('test1');

        $firstInstanceId = CachingTestMiddleware::$instanceId;

        // Flush cache
        $pendingA->flushCache();

        // Second request creates new instance
        $pendingB = $client->withRequestMiddleware([CachingTestMiddleware::class]);
        Http::fake(['test2' => Http::response('{"data": "test2"}')]);
        $pendingB->get('test2');

        $this->assertNotEquals($firstInstanceId, CachingTestMiddleware::$instanceId);
        CachingTestMiddleware::reset();
    }

    public function testInvalidMiddlewareClassThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Middleware class `NonExistentMiddleware` does not exist');

        $client = new ApiClient();
        $pending = $client->withRequestMiddleware(['NonExistentMiddleware']);

        Http::fake(['test' => Http::response('{"data": "test"}')]);
        $pending->get('test');
    }

    public function testRequestMiddlewarePipelineFlow(): void
    {
        $client = new ApiClient();

        PipelineTestMiddleware::reset();

        $pending = $client->withRequestMiddleware([
            FirstPipelineMiddleware::class,
            SecondPipelineMiddleware::class,
        ]);

        Http::fake(['test' => Http::response('{"success": true}')]);
        $pending->get('test');

        // Verify both middleware were called in correct order
        $this->assertEquals(['first', 'second'], PipelineTestMiddleware::$calls);
        PipelineTestMiddleware::reset();
    }

    public function testResponseMiddlewarePipelineFlow(): void
    {
        $client = new ApiClient();

        PipelineTestMiddleware::reset();

        $pending = $client->withResponseMiddleware([
            FirstResponsePipelineMiddleware::class,
            SecondResponsePipelineMiddleware::class,
        ]);

        Http::fake(['test' => Http::response('{"success": true}')]);
        $pending->get('test');

        // Verify both middleware were called in correct order
        $this->assertEquals(['first-response', 'second-response'], PipelineTestMiddleware::$calls);
        PipelineTestMiddleware::reset();
    }

    public function testMiddlewareReceivesClientConfig(): void
    {
        $client = new FooApiClient($config = [
            'api_key' => 'test-key',
            'base_url' => 'https://api.test.com',
        ]);

        $pending = $client->withRequestMiddleware([ConfigCheckingMiddleware::class]);

        Http::fake(['test' => Http::response('{"success": true}')]);
        $pending->get('test');

        $this->assertEquals($config, ConfigCheckingMiddleware::$receivedConfig?->toArray());
        ConfigCheckingMiddleware::reset();
    }

    public function testCombinedRequestAndResponseMiddleware(): void
    {
        $client = new ApiClient();

        $pending = $client
            ->withRequestMiddleware([AddHeaderRequestMiddleware::class])
            ->withResponseMiddleware([AddHeaderResponseMiddleware::class]);

        Http::fake(['test' => Http::response('{"success": true}')]);
        $response = $pending->get('test');

        // Verify request middleware was applied
        Http::assertSent(function (Request $request) {
            return $request->hasHeader('X-Custom-Header')
                && $request->header('X-Custom-Header')[0] === 'middleware-value';
        });

        // Verify response middleware was applied
        $this->assertTrue($response->hasHeader('X-Response-Header'));
        $this->assertEquals(
            'response-value',
            $response->header('X-Response-Header')
        );
    }
}

// Test middleware classes
class TestRequestMiddleware
{
    public static bool $called = false;

    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        self::$called = true;
        return $next($request);
    }

    public static function reset(): void
    {
        self::$called = false;
    }
}

class AnotherRequestMiddleware
{
    public static bool $called = false;

    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        self::$called = true;
        return $next($request);
    }

    public static function reset(): void
    {
        self::$called = false;
    }
}

class TestResponseMiddleware
{
    public static bool $called = false;

    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiResponse $response, callable $next): ApiResponse
    {
        self::$called = true;
        return $next($response);
    }

    public static function reset(): void
    {
        self::$called = false;
    }
}

class AnotherResponseMiddleware
{
    public static bool $called = false;

    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiResponse $response, callable $next): ApiResponse
    {
        self::$called = true;
        return $next($response);
    }

    public static function reset(): void
    {
        self::$called = false;
    }
}

class AddHeaderRequestMiddleware
{
    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        $request = $request->withHeader('X-Custom-Header', 'middleware-value');
        return $next($request);
    }
}

class AddHeaderResponseMiddleware
{
    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiResponse $response, callable $next): ApiResponse
    {
        $response = $response->withHeader('X-Response-Header', 'response-value');
        return $next($response);
    }
}

class OrderTrackingMiddleware
{
    public static array $order = [];

    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        self::$order[] = 1;
        return $next($request);
    }

    public static function reset(): void
    {
        self::$order = [];
    }
}

class SecondOrderTrackingMiddleware
{
    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        OrderTrackingMiddleware::$order[] = 2;
        return $next($request);
    }
}

class OptionsCheckingMiddleware
{
    public static ?array $receivedOptions = null;

    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        self::$receivedOptions = $request->context('options');
        return $next($request);
    }

    public static function reset(): void
    {
        self::$receivedOptions = null;
    }
}

class CachingTestMiddleware
{
    public static ?string $instanceId = null;

    private string $id;

    public function __construct(protected ?array $config = null)
    {
        $this->id = uniqid('middleware_', true);
        self::$instanceId = $this->id;
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        return $next($request);
    }

    public static function reset(): void
    {
        self::$instanceId = null;
    }
}

class PipelineTestMiddleware
{
    public static array $calls = [];

    public static function reset(): void
    {
        self::$calls = [];
    }
}

class FirstPipelineMiddleware
{
    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        PipelineTestMiddleware::$calls[] = 'first';
        return $next($request);
    }
}

class SecondPipelineMiddleware
{
    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        PipelineTestMiddleware::$calls[] = 'second';
        return $next($request);
    }
}

class FirstResponsePipelineMiddleware
{
    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiResponse $response, callable $next): ApiResponse
    {
        PipelineTestMiddleware::$calls[] = 'first-response';
        return $next($response);
    }
}

class SecondResponsePipelineMiddleware
{
    public function __construct(protected ?array $config = null)
    {
    }

    public function handle(ApiResponse $response, callable $next): ApiResponse
    {
        PipelineTestMiddleware::$calls[] = 'second-response';
        return $next($response);
    }
}

class ConfigCheckingMiddleware
{
    public static ?ConfigDataObject $receivedConfig = null;

    public function __construct(protected ?ConfigDataObject $config = null)
    {
        self::$receivedConfig = $config;
    }

    public function handle(ApiRequest $request, callable $next): ApiRequest
    {
        return $next($request);
    }

    public static function reset(): void
    {
        self::$receivedConfig = null;
    }
}

class ConfigDataObject extends DataObject
{
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
    ) {
    }
}

class FooApiClient extends ApiClient
{
    public function __construct(array $config = [])
    {
        $this->config = ConfigDataObject::make($config);
    }
}
