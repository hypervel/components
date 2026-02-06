<?php

declare(strict_types=1);

namespace Hypervel\Tests\Guzzle;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\RequestOptions;
use GuzzleHttp\TransferStats;
use Hypervel\Foundation\Testing\Concerns\RunTestsInCoroutine;
use Hypervel\Guzzle\CoroutineHandler;
use Hypervel\Tests\Guzzle\Stub\CoroutineHandlerStub;
use Hypervel\Tests\TestCase;
use Mockery as m;

/**
 * @internal
 * @coversNothing
 */
class CoroutineHandlerTest extends TestCase
{
    use RunTestsInCoroutine;

    /**
     * Test that connection errors are properly caught and converted to ConnectException.
     */
    /**
     * Test that connection errors are properly caught and converted to ConnectException.
     *
     * The handler wraps underlying connection errors in a ConnectException with
     * a standardized message format: "Failed to connecting to {host} port {port}, {error}"
     */
    public function testCreatesCurlErrors()
    {
        $handler = new CoroutineHandler();
        $request = new Request('GET', 'http://localhost:123');
        try {
            $handler($request, ['timeout' => 0.001, 'connect_timeout' => 0.001])->wait();
            $this->fail('Expected ConnectException was not thrown');
        } catch (Exception $exception) {
            $this->assertInstanceOf(ConnectException::class, $exception);
            // Message format: "Failed to connecting to {host} port {port}, {underlying error}"
            $this->assertStringStartsWith('Failed to connecting to localhost port 123', $exception->getMessage());
        }
    }

    /**
     * Test that the handler returns promises for async requests.
     */
    public function testReusesHandles()
    {
        $handler = new CoroutineHandler();
        $request = new Request('GET', 'https://pokeapi.co/api/v2/pokemon/');
        $result1 = $handler($request, []);
        $request = new Request('GET', 'https://pokeapi.co/api/v2/pokemon/');
        $result2 = $handler($request, []);

        $this->assertInstanceOf(PromiseInterface::class, $result1);
        $this->assertInstanceOf(PromiseInterface::class, $result2);
    }

    /**
     * Test that the delay option causes the handler to sleep.
     */
    public function testDoesSleep()
    {
        $handler = new CoroutineHandlerStub();
        $request = new Request('GET', 'https://pokeapi.co/api/v2/pokemon/');
        $response = $handler($request, ['delay' => 1, 'timeout' => 5])->wait();

        $json = json_decode((string) $response->getBody(), true);

        $this->assertSame(5, $json['setting']['timeout']);
    }

    /**
     * Test that connection errors include handler context with error code.
     */
    public function testCreatesErrorsWithContext()
    {
        $handler = new CoroutineHandler();
        $request = new Request('GET', 'http://localhost:123');
        $called = false;
        $promise = $handler($request, ['timeout' => 0.001])
            ->otherwise(function (ConnectException $exception) use (&$called) {
                $called = true;
                $this->assertArrayHasKey('errCode', $exception->getHandlerContext());
            });
        $promise->wait();
        $this->assertTrue($called);
    }

    /**
     * Test that the handler works correctly with a Guzzle client.
     */
    public function testGuzzleClient()
    {
        $client = new Client([
            'base_uri' => 'http://127.0.0.1:8080',
            'handler' => HandlerStack::create(new CoroutineHandlerStub()),
        ]);

        $response = (string) $client->get('/echo', [
            'timeout' => 10,
            'headers' => [
                'X-TOKEN' => md5('1234'),
            ],
            'json' => [
                'id' => 1,
            ],
        ])->getBody();

        $result = json_decode($response, true);

        $this->assertSame('127.0.0.1', $result['host']);
        $this->assertSame(8080, $result['port']);
        $this->assertSame(false, $result['ssl']);
        $this->assertSame([md5('1234')], $result['headers']['X-TOKEN']);

        // Test with real external API
        $client = new Client([
            'base_uri' => 'https://pokeapi.co',
            'timeout' => 5,
            'handler' => HandlerStack::create(new CoroutineHandler()),
        ]);

        $response = (string) $client->get('/api/v2/pokemon')->getBody();

        $this->assertNotEmpty($response);
    }

    /**
     * Test that Swoole-specific settings are passed through.
     */
    public function testSwooleSetting()
    {
        $client = new Client([
            'base_uri' => 'http://127.0.0.1:8080',
            'handler' => HandlerStack::create(new CoroutineHandlerStub()),
            'timeout' => 5,
            'swoole' => [
                'timeout' => 10,
                'socket_buffer_size' => 1024 * 1024 * 2,
            ],
        ]);

        $data = json_decode((string) $client->get('/')->getBody(), true);

        $this->assertSame(10, $data['setting']['timeout']);
        $this->assertSame(1024 * 1024 * 2, $data['setting']['socket_buffer_size']);
    }

    /**
     * Test that proxy settings are correctly configured.
     */
    public function testProxy()
    {
        $client = new Client([
            'base_uri' => 'http://127.0.0.1:8080',
            'handler' => HandlerStack::create(new CoroutineHandlerStub()),
            'proxy' => 'http://user:pass@127.0.0.1:8081',
        ]);

        $json = json_decode((string) $client->get('/')->getBody(), true);

        $setting = $json['setting'];

        $this->assertSame('127.0.0.1', $setting['http_proxy_host']);
        $this->assertSame(8081, $setting['http_proxy_port']);
        $this->assertSame('user', $setting['http_proxy_user']);
        $this->assertSame('pass', $setting['http_proxy_password']);
    }

    /**
     * Test that proxy array selects HTTP proxy for HTTP scheme.
     */
    public function testProxyArrayHttpScheme()
    {
        $client = new Client([
            'base_uri' => 'http://127.0.0.1:8080',
            'handler' => HandlerStack::create(new CoroutineHandlerStub()),
            'proxy' => [
                'http' => 'http://127.0.0.1:12333',
                'https' => 'http://127.0.0.1:12334',
                'no' => ['.cn'],
            ],
        ]);

        $json = json_decode((string) $client->get('/')->getBody(), true);

        $setting = $json['setting'];

        $this->assertSame('127.0.0.1', $setting['http_proxy_host']);
        $this->assertSame(12333, $setting['http_proxy_port']);
        $this->assertArrayNotHasKey('http_proxy_user', $setting);
        $this->assertArrayNotHasKey('http_proxy_password', $setting);
    }

    /**
     * Test that proxy array selects HTTPS proxy for HTTPS scheme.
     */
    public function testProxyArrayHttpsScheme()
    {
        $client = new Client([
            'base_uri' => 'https://www.baidu.com',
            'handler' => HandlerStack::create(new CoroutineHandlerStub()),
            'proxy' => [
                'http' => 'http://127.0.0.1:12333',
                'https' => 'http://127.0.0.1:12334',
                'no' => ['.cn'],
            ],
        ]);

        $json = json_decode((string) $client->get('/')->getBody(), true);

        $setting = $json['setting'];

        $this->assertSame('127.0.0.1', $setting['http_proxy_host']);
        $this->assertSame(12334, $setting['http_proxy_port']);
        $this->assertArrayNotHasKey('http_proxy_user', $setting);
        $this->assertArrayNotHasKey('http_proxy_password', $setting);
    }

    /**
     * Test that proxy is skipped when host matches no-proxy list.
     */
    public function testProxyArrayHostInNoproxy()
    {
        $client = new Client([
            'base_uri' => 'https://www.baidu.cn',
            'handler' => HandlerStack::create(new CoroutineHandlerStub()),
            'proxy' => [
                'http' => 'http://127.0.0.1:12333',
                'https' => 'http://127.0.0.1:12334',
                'no' => ['.cn'],
            ],
        ]);

        $json = json_decode((string) $client->get('/')->getBody(), true);

        $setting = $json['setting'];

        $this->assertArrayNotHasKey('http_proxy_host', $setting);
        $this->assertArrayNotHasKey('http_proxy_port', $setting);
    }

    /**
     * Test that SSL key and certificate options are passed through.
     */
    public function testSslKeyAndCert()
    {
        $client = new Client([
            'base_uri' => 'http://127.0.0.1:8080',
            'handler' => HandlerStack::create(new CoroutineHandlerStub()),
            'timeout' => 5,
            'cert' => 'apiclient_cert.pem',
            'ssl_key' => 'apiclient_key.pem',
        ]);

        $data = json_decode((string) $client->get('/')->getBody(), true);

        $this->assertSame('apiclient_cert.pem', $data['setting']['ssl_cert_file']);
        $this->assertSame('apiclient_key.pem', $data['setting']['ssl_key_file']);

        $client = new Client([
            'base_uri' => 'http://127.0.0.1:8080',
            'handler' => HandlerStack::create(new CoroutineHandlerStub()),
            'timeout' => 5,
        ]);

        $data = json_decode((string) $client->get('/')->getBody(), true);

        $this->assertArrayNotHasKey('ssl_cert_file', $data['setting']);
        $this->assertArrayNotHasKey('ssl_key_file', $data['setting']);
    }

    /**
     * Test that user info in URI is converted to Basic auth header.
     */
    public function testUserInfo()
    {
        $url = 'https://username:password@127.0.0.1:8080';
        $handler = new CoroutineHandlerStub();
        $request = new Request('GET', $url . '/echo');

        $response = $handler($request, ['timeout' => 5])->wait();
        $content = (string) $response->getBody();
        $json = json_decode($content, true);

        $this->assertSame('Basic ' . base64_encode('username:password'), $json['headers']['Authorization']);
    }

    /**
     * Test that ON_STATS callback is called with transfer stats.
     */
    public function testRequestOptionOnStats()
    {
        $url = 'http://127.0.0.1:9501';
        $handler = new CoroutineHandlerStub();
        $request = new Request('GET', $url . '/echo');

        $called = false;
        $handler($request, [RequestOptions::ON_STATS => function (TransferStats $stats) use (&$called) {
            $called = true;
            $this->assertIsFloat($stats->getTransferTime());
        }])->wait();
        $this->assertTrue($called);
    }

    /**
     * Test that ON_STATS callback works when configured on client.
     */
    public function testRequestOptionOnStatsInClient()
    {
        $called = false;
        $url = 'http://127.0.0.1:9501';
        $client = new Client([
            'handler' => new CoroutineHandlerStub(),
            'base_uri' => $url,
            RequestOptions::ON_STATS => function (TransferStats $stats) use (&$called) {
                $called = true;
                $this->assertIsFloat($stats->getTransferTime());
            },
        ]);
        $client->get('/');
        $this->assertTrue($called);
    }

    /**
     * Test that response body can be written to a file sink.
     */
    public function testSink()
    {
        $dir = sys_get_temp_dir() . '/hypervel-guzzle-test/';
        @mkdir($dir, 0755, true);

        $handler = new CoroutineHandlerStub();
        $stream = $handler->createSink($body = uniqid(), $sink = $dir . uniqid());
        $this->assertSame($body, file_get_contents($sink));
        $this->assertSame('', stream_get_contents($stream));

        $stream = $handler->createSink($body = uniqid(), $sink);
        $this->assertSame($body, file_get_contents($sink));
        $this->assertSame('', stream_get_contents($stream));
        fseek($stream, 0);
        $this->assertSame($body, stream_get_contents($stream));
    }

    /**
     * Test that response body can be written to a resource sink.
     */
    public function testResourceSink()
    {
        $dir = sys_get_temp_dir() . '/hypervel-guzzle-test/';
        @mkdir($dir, 0755, true);
        $sink = fopen($file = $dir . uniqid(), 'w+');
        $handler = new CoroutineHandlerStub();
        $stream = $handler->createSink($body1 = uniqid(), $sink);
        $this->assertSame('', stream_get_contents($stream));
        $stream = $handler->createSink($body2 = uniqid(), $sink);
        $this->assertSame('', stream_get_contents($stream));
        $this->assertSame($body1 . $body2, file_get_contents($file));
        fseek($sink, 0);
        $this->assertSame($body1 . $body2, stream_get_contents($stream));
    }

    /**
     * Test that Expect and Content-Length headers are removed by default.
     *
     * These headers can cause issues with Swoole's coroutine HTTP client.
     */
    public function testExpect100Continue()
    {
        $url = 'http://127.0.0.1:9501';
        $client = new Client([
            'handler' => HandlerStack::create(new CoroutineHandlerStub()),
            'base_uri' => $url,
        ]);
        $response = $client->post('/', [
            RequestOptions::JSON => [
                'data' => str_repeat(uniqid(), 100000),
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $this->assertArrayNotHasKey('Content-Length', $data['headers']);
        $this->assertArrayNotHasKey('Expect', $data['headers']);

        $stub = m::mock(CoroutineHandlerStub::class . '[rewriteHeaders]');
        $stub->shouldReceive('rewriteHeaders')->withAnyArgs()->andReturnUsing(function ($headers) {
            return $headers;
        });

        $client = new Client([
            'handler' => HandlerStack::create($stub),
            'base_uri' => $url,
        ]);
        $response = $client->post('/', [
            RequestOptions::JSON => [
                'data' => str_repeat(uniqid(), 100000),
            ],
        ]);

        $data = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('Content-Length', $data['headers']);
        $this->assertArrayHasKey('Expect', $data['headers']);
    }

    /**
     * Create a new CoroutineHandler instance.
     */
    protected function getHandler(array $options = []): CoroutineHandler
    {
        return new CoroutineHandler();
    }
}
