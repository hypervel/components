<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http;

use GuzzleHttp\ClientInterface as GuzzleClientInterface;
use GuzzleHttp\Multiplexing;
use GuzzleHttp\Psr7\MultipartStream;
use GuzzleHttp\Psr7\NoSeekStream;
use GuzzleHttp\Psr7\PumpStream;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response as Psr7Response;
use GuzzleHttp\Psr7\Utils;
use GuzzleHttp\RequestOptions;
use Hypervel\Contracts\Engine\Http\ClientInterface as EngineClientInterface;
use Hypervel\Contracts\Engine\Http\RawResponseInterface;
use Hypervel\Http\Client\SwooleHandler;
use Hypervel\Http\Client\SwooleRequest;
use Hypervel\Http\Client\UnsupportedTransportException;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use ReflectionClass;
use stdClass;

use function Hypervel\Coroutine\run;

class SwooleHandlerTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testEveryPublicRequestOptionHasOneClassification(): void
    {
        $handlerReflection = new ReflectionClass(SwooleHandler::class);
        $commonOptions = $handlerReflection->getConstant('COMMON_REQUEST_OPTIONS');
        $guzzleEightOptions = $handlerReflection->getConstant('GUZZLE_8_REQUEST_OPTIONS');
        $internalOptions = $handlerReflection->getConstant('INTERNAL_OPTIONS');

        $this->assertIsArray($commonOptions);
        $this->assertIsArray($guzzleEightOptions);
        $this->assertIsArray($internalOptions);

        $classifiedOptions = array_keys($commonOptions);

        // @TODO: Remove Guzzle 7 compatibility when Hypervel requires Guzzle 8; classify one unified option map.
        if (GuzzleClientInterface::MAJOR_VERSION >= 8) {
            $classifiedOptions = array_merge($classifiedOptions, array_keys($guzzleEightOptions));
        }

        $publicOptions = array_values((new ReflectionClass(RequestOptions::class))->getConstants());

        sort($classifiedOptions);
        sort($publicOptions);

        $this->assertSame($publicOptions, $classifiedOptions);
        $this->assertNotContains('_conditional', $internalOptions);
        $this->assertContains('handler', $internalOptions);
    }

    public function testPreparesACompleteNativeRequest(): void
    {
        $body = Utils::streamFor('payload');
        $request = new Request(
            'POST',
            'https://Example.com:8443/path?query=yes',
            ['Authorization' => 'Basic dXNlcjpwYXNz', 'X-Test' => ['one', 'two']],
            $body,
        );

        $prepared = $this->prepareNative($request, [
            'auth' => ['user', 'pass'],
            'protocols' => ['https'],
            'multiplex' => Multiplexing::NONE,
            'retries' => 0,
            'decode_content' => 'gzip',
            'delay' => 250.5,
            'connect_timeout' => 1,
            'timeout' => 2.5,
            'read_timeout' => 3,
            'verify' => false,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
            'crypto_method_max' => STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
        ]);

        $this->assertSame('example.com', $prepared->host);
        $this->assertSame(8443, $prepared->port);
        $this->assertTrue($prepared->ssl);
        $this->assertSame([
            'ssl_verify_peer' => false,
            'ssl_allow_self_signed' => true,
            'ssl_host_name' => 'example.com',
            'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
        ], $prepared->constructionSettings);
        $this->assertSame([
            'connect_timeout' => 1.0,
            'timeout' => 2.5,
            'read_timeout' => 3.0,
            'body_decompression' => true,
        ], $prepared->transferSettings);
        $this->assertSame('POST', $prepared->method);
        $this->assertSame('/path?query=yes', $prepared->path);
        $this->assertSame($request->getHeaders(), $prepared->headers);
        $this->assertSame($body, $prepared->body);
        $this->assertSame('1.1', $prepared->version);
        $this->assertTrue($prepared->decodeContent);
        $this->assertSame(250500, $prepared->delayMicroseconds);
    }

    public function testNormalizesAnIpv6ConnectionHostWithoutChangingThePreparedHostHeader(): void
    {
        $request = new Request('GET', 'https://[::1]:8443/path');

        $prepared = $this->prepareNative($request, [
            'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
        ]);

        $this->assertSame('::1', $prepared->host);
        $this->assertSame('::1', $prepared->constructionSettings['ssl_host_name']);
        $this->assertSame('[::1]:8443', $prepared->headers['Host'][0]);
    }

    public function testRefusesUriUserinfoInsteadOfReimplementingAuthentication(): void
    {
        $result = $this->prepareInCoroutine(
            new Request('GET', 'http://USER%40example.com:p%40ss@example.com/path'),
        );

        $this->assertSame('URI userinfo authentication requires the Guzzle transport', $result);
    }

    public function testRefusesNativePreparationOutsideACoroutine(): void
    {
        $result = (new SwooleHandler)->prepare(
            new Request('GET', 'http://example.com'),
            ['synchronous' => true],
        );

        $this->assertSame('the Swoole transport requires a coroutine', $result);
    }

    #[DataProvider('unsupportedRequestProvider')]
    public function testRefusesUnsupportedRequestMetadata(
        string $uri,
        array $options,
        string $expected,
        array $headers = [],
        string $version = '1.1',
    ): void {
        $result = $this->prepareInCoroutine(
            new Request('GET', $uri, $headers, null, $version),
            $options,
        );

        $this->assertSame($expected, $result);
    }

    public static function unsupportedRequestProvider(): array
    {
        return [
            'asynchronous' => [
                'http://example.com',
                ['synchronous' => false],
                'asynchronous requests require the Guzzle transport',
            ],
            'scheme' => [
                'ftp://example.com',
                [],
                'URI scheme [ftp] requires the Guzzle transport',
            ],
            'host' => [
                'http:/path',
                [],
                'requests without a URI host require the Guzzle transport',
            ],
            'version' => [
                'http://example.com',
                [],
                'HTTP/2 requires the Guzzle transport',
                [],
                '2',
            ],
            'numeric option' => [
                'http://example.com',
                [0 => true],
                'numeric request options require the Guzzle transport',
            ],
            'unknown option' => [
                'http://example.com',
                ['future_option' => true],
                'request option [future_option] is unknown to the Swoole transport',
            ],
            'digest auth' => [
                'http://example.com',
                ['auth' => ['user', 'pass', 'digest']],
                'the configured authentication method requires the Guzzle transport',
            ],
            'protocol type' => [
                'http://example.com',
                ['protocols' => 'http'],
                'the protocols request option must be an array',
            ],
            'protocol value' => [
                'http://example.com',
                ['protocols' => ['https']],
                'URI scheme [http] is not permitted by the request protocols option',
            ],
            'multiplexing' => [
                'http://example.com',
                ['multiplex' => Multiplexing::EAGER],
                'connection multiplexing requires the Guzzle transport',
            ],
            'transport retry' => [
                'http://example.com',
                ['retries' => 1],
                'transport-level retries require the Guzzle transport',
            ],
            'expect header' => [
                'http://example.com',
                [],
                'the Expect request header requires the Guzzle transport',
                ['Expect' => '100-continue'],
            ],
            'chunked body' => [
                'http://example.com',
                [],
                'chunked request bodies require the Guzzle transport',
                ['Transfer-Encoding' => 'chunked'],
            ],
            'decode content' => [
                'http://example.com',
                ['decode_content' => 1],
                'the decode_content request option must be a boolean or string',
            ],
            'delay type' => [
                'http://example.com',
                ['delay' => '1'],
                'the delay request option must be a non-negative finite number of milliseconds',
            ],
            'negative delay' => [
                'http://example.com',
                ['delay' => -1],
                'the delay request option must be a non-negative finite number of milliseconds',
            ],
            'infinite delay' => [
                'http://example.com',
                ['delay' => INF],
                'the delay request option must be a non-negative finite number of milliseconds',
            ],
            'timeout type' => [
                'http://example.com',
                ['timeout' => '1'],
                'request option [timeout] must be a non-negative finite number',
            ],
            'negative timeout' => [
                'http://example.com',
                ['read_timeout' => -1],
                'request option [read_timeout] must be a non-negative finite number',
            ],
        ];
    }

    public function testEveryActiveFallbackOptionRequiresGuzzle(): void
    {
        $reflection = new ReflectionClass(SwooleHandler::class);
        $options = $reflection->getConstant('COMMON_REQUEST_OPTIONS');

        $this->assertIsArray($options);

        foreach ($options as $name => $category) {
            if ($category !== 'fallback') {
                continue;
            }

            $value = $name === 'curl' ? [CURLOPT_TIMEOUT => 1] : true;
            $result = $this->prepareInCoroutine(
                new Request('GET', 'http://example.com'),
                [$name => $value],
            );

            $this->assertSame(
                "request option [{$name}] requires the Guzzle transport",
                $result,
                $name,
            );
        }
    }

    public function testInactiveFallbackOptionsDoNotPreventNativePreparation(): void
    {
        $prepared = $this->prepareNative(new Request('GET', 'http://example.com'), [
            'curl' => [],
            'debug' => false,
            'on_headers' => null,
            'on_trailers' => false,
            'progress' => [],
            'proxy' => false,
            'sink' => null,
            'stream' => false,
            'stream_context' => [],
        ]);

        $this->assertSame('example.com', $prepared->host);
    }

    public function testPreparedInternalOptionsDoNotPreventNativePreparation(): void
    {
        $prepared = $this->prepareNative(new Request('GET', 'http://example.com'), [
            'handler' => static fn () => null,
            'hypervel_data' => ['value' => true],
            'no_sentry_aspect' => true,
            'telescope_enabled' => true,
            'telescope_tags' => ['tag'],
        ]);

        $this->assertSame('example.com', $prepared->host);
    }

    public function testRemovedConditionalOptionFailsClosed(): void
    {
        $result = $this->prepareInCoroutine(
            new Request('GET', 'http://example.com'),
            ['_conditional' => []],
        );

        $this->assertSame(
            'request option [_conditional] is unknown to the Swoole transport',
            $result,
        );
    }

    public function testClassifiesRequestBodyTypesWithoutReadingThem(): void
    {
        $multipart = new MultipartStream([
            ['name' => 'field', 'contents' => 'value'],
        ]);
        $nonSeekable = new NoSeekStream(Utils::streamFor('value'));
        $unknownLength = new PumpStream(static fn () => false);
        $file = fopen(__FILE__, 'r');

        $this->assertIsResource($file);
        $fileStream = Utils::streamFor($file);

        try {
            $cases = [
                'multipart request bodies require the Guzzle transport' => $multipart,
                'non-seekable or unknown-length request bodies require the Guzzle transport' => $nonSeekable,
                'file-backed request bodies require the Guzzle transport' => $fileStream,
            ];

            foreach ($cases as $expected => $body) {
                $position = $body->isSeekable() ? $body->tell() : null;
                $result = $this->prepareInCoroutine(
                    new Request('POST', 'http://example.com', [], $body),
                );

                $this->assertSame($expected, $result);

                if ($position !== null) {
                    $this->assertSame($position, $body->tell());
                }
            }

            $this->assertSame(
                'non-seekable or unknown-length request bodies require the Guzzle transport',
                $this->prepareInCoroutine(
                    new Request('POST', 'http://example.com', [], $unknownLength),
                ),
            );
        } finally {
            $multipart->close();
            $nonSeekable->close();
            $unknownLength->close();
            $fileStream->close();
        }
    }

    public function testAcceptsAnEmptyNonSeekableBody(): void
    {
        $body = new NoSeekStream(Utils::streamFor(''));

        try {
            $prepared = $this->prepareNative(
                new Request('POST', 'http://example.com', [], $body),
            );

            $this->assertSame($body, $prepared->body);
        } finally {
            $body->close();
        }
    }

    #[DataProvider('tlsRangeProvider')]
    public function testTranslatesTlsRanges(int $minimum, ?int $maximum, int $expected): void
    {
        $options = ['crypto_method' => $minimum];

        if ($maximum !== null) {
            $options['crypto_method_max'] = $maximum;
        }

        $prepared = $this->prepareNative(
            new Request('GET', 'https://example.com'),
            $options,
        );

        $this->assertSame($expected, $prepared->constructionSettings['ssl_protocols']);
    }

    public static function tlsRangeProvider(): array
    {
        return [
            'all explicit' => [
                STREAM_CRYPTO_METHOD_TLSv1_0_CLIENT,
                STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                SWOOLE_SSL_TLSv1 | SWOOLE_SSL_TLSv1_1 | SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
            ],
            'TLS 1.2 and 1.3' => [
                STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
            ],
            'TLS 1.3 only' => [
                STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                null,
                SWOOLE_SSL_TLSv1_3,
            ],
        ];
    }

    #[DataProvider('invalidTlsProvider')]
    public function testRefusesUnrepresentableTlsConfiguration(array $options, string $expected): void
    {
        $result = $this->prepareInCoroutine(
            new Request('GET', 'https://example.com'),
            $options,
        );

        $this->assertSame($expected, $result);
    }

    public static function invalidTlsProvider(): array
    {
        return [
            'minimum type' => [
                ['crypto_method' => 'TLSv1.2'],
                'the crypto_method request option cannot be represented by Swoole',
            ],
            'minimum value' => [
                ['crypto_method' => -1],
                'the crypto_method request option cannot be represented by Swoole',
            ],
            'maximum type' => [
                [
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    'crypto_method_max' => 'TLSv1.3',
                ],
                'the crypto_method_max request option cannot be represented by Swoole',
            ],
            'reversed range' => [
                [
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT,
                    'crypto_method_max' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                ],
                'the configured maximum TLS version is lower than the minimum TLS version',
            ],
            'verify type' => [
                [
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    'verify' => 1,
                ],
                'the verify request option must be a boolean or CA bundle path',
            ],
            'missing private key' => [
                [
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    'cert' => '/tmp/client.pem',
                ],
                'native client certificates require both cert and ssl_key request options',
            ],
            'protected certificate' => [
                [
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    'cert' => ['/tmp/client.pem', 'password'],
                    'ssl_key' => '/tmp/client.key',
                ],
                'password-protected client certificates require the Guzzle transport',
            ],
            'certificate type' => [
                [
                    'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT,
                    'cert' => '/tmp/client.pem',
                    'ssl_key' => '/tmp/client.key',
                    'cert_type' => 'DER',
                ],
                'non-PEM client certificates require the Guzzle transport',
            ],
        ];
    }

    public function testAppliesGuzzleMajorSpecificImplicitTlsMinimum(): void
    {
        $result = $this->prepareInCoroutine(new Request('GET', 'https://example.com'));

        // @TODO: Remove Guzzle 7 compatibility when Hypervel requires Guzzle 8; keep only the native TLS floor assertion.
        if (GuzzleClientInterface::MAJOR_VERSION < 8) {
            $this->assertSame(
                'Guzzle 7 requests without an explicit TLS minimum require the Guzzle transport',
                $result,
            );

            return;
        }

        $this->assertInstanceOf(SwooleRequest::class, $result);
        $this->assertSame(
            SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
            $result->constructionSettings['ssl_protocols'],
        );
    }

    public function testPlainHttpValidatesTlsConstantsButOmitsTlsConstructionSettings(): void
    {
        $prepared = $this->prepareNative(new Request('GET', 'http://example.com'), [
            'crypto_method_max' => STREAM_CRYPTO_METHOD_TLSv1_1_CLIENT,
            'verify' => new stdClass,
            'cert' => '/not/read',
        ]);

        $this->assertSame([], $prepared->constructionSettings);
    }

    public function testSendRewindsTheBodyAndConvertsDecodedResponseMetadata(): void
    {
        $body = Utils::streamFor('payload');
        $body->getContents();
        $prepared = $this->prepareNative(
            new Request('POST', 'http://example.com/path', ['X-Test' => 'yes'], $body),
        );
        $rawResponse = m::mock(RawResponseInterface::class);
        $rawResponse->shouldReceive('getHeaders')->once()->andReturn([
            'Content-Encoding' => ['gzip'],
            'content-length' => ['25'],
            'Set-Cookie' => ['first=1'],
            'set-cookie' => ['second=2'],
        ]);
        $rawResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
        $rawResponse->shouldReceive('getVersion')->once()->andReturn('1.1');
        $rawResponse->shouldReceive('getBody')->once()->andReturn('decoded');

        $client = m::mock(EngineClientInterface::class);
        $client->shouldReceive('set')->once()->with($prepared->transferSettings);
        $client->shouldReceive('request')->once()->with(
            'POST',
            '/path',
            $prepared->headers,
            'payload',
            '1.1',
        )->andReturn($rawResponse);

        $response = (new SwooleHandler)->send($client, $prepared, []);

        $this->assertSame('decoded', (string) $response->getBody());
        $this->assertSame('OK', $response->getReasonPhrase());
        $this->assertSame(['gzip'], $response->getHeader('x-encoded-content-encoding'));
        $this->assertSame(['25'], $response->getHeader('x-encoded-content-length'));
        $this->assertFalse($response->hasHeader('Content-Encoding'));
        $this->assertFalse($response->hasHeader('Content-Length'));
        $this->assertSame(['first=1', 'second=2'], $response->getHeader('Set-Cookie'));
    }

    public function testSendPreservesEncodedResponseWhenDecodingIsDisabled(): void
    {
        $prepared = $this->prepareNative(
            new Request('GET', 'http://example.com'),
            ['decode_content' => false],
        );
        $rawResponse = m::mock(RawResponseInterface::class);
        $rawResponse->shouldReceive('getHeaders')->once()->andReturn([
            'Content-Encoding' => ['gzip'],
            'Content-Length' => ['25'],
        ]);
        $rawResponse->shouldReceive('getStatusCode')->once()->andReturn(200);
        $rawResponse->shouldReceive('getVersion')->once()->andReturn('1.1');
        $rawResponse->shouldReceive('getBody')->once()->andReturn('encoded');

        $client = m::mock(EngineClientInterface::class);
        $client->shouldReceive('set')->once()->with($prepared->transferSettings);
        $client->shouldReceive('request')->once()->andReturn($rawResponse);

        $response = (new SwooleHandler)->send($client, $prepared, []);

        $this->assertSame(['gzip'], $response->getHeader('Content-Encoding'));
        $this->assertSame(['25'], $response->getHeader('Content-Length'));
        $this->assertFalse($response->hasHeader('x-encoded-content-encoding'));
        $this->assertSame('encoded', (string) $response->getBody());
    }

    public function testSendHonorsGuzzleEightResponseAndStreamFactories(): void
    {
        $prepared = $this->prepareNative(new Request('GET', 'http://example.com'));
        $rawResponse = m::mock(RawResponseInterface::class);
        $rawResponse->shouldReceive('getHeaders')->once()->andReturn(['X-Origin' => ['yes']]);
        $rawResponse->shouldReceive('getStatusCode')->once()->andReturn(201);
        $rawResponse->shouldReceive('getVersion')->once()->andReturn('1.1');
        $rawResponse->shouldReceive('getBody')->once()->andReturn('origin');

        $client = m::mock(EngineClientInterface::class);
        $client->shouldReceive('set')->once();
        $client->shouldReceive('request')->once()->andReturn($rawResponse);

        $streamFactory = m::mock(StreamFactoryInterface::class);
        $streamFactory->shouldReceive('createStream')->once()->with('origin')->andReturn(
            Utils::streamFor('factory'),
        );
        $responseFactory = m::mock(ResponseFactoryInterface::class);
        $responseFactory->shouldReceive('createResponse')->once()->with(201)->andReturn(
            new Psr7Response(201, ['X-Factory' => 'yes']),
        );

        $response = (new SwooleHandler)->send($client, $prepared, [
            'stream_factory' => $streamFactory,
            'response_factory' => $responseFactory,
        ]);

        $this->assertSame('factory', (string) $response->getBody());
        $this->assertSame('yes', $response->getHeaderLine('X-Factory'));
        $this->assertSame('yes', $response->getHeaderLine('X-Origin'));
    }

    public function testSendRejectsInvalidFactoriesBeforeTouchingTheEngineClient(): void
    {
        $prepared = $this->prepareNative(new Request('GET', 'http://example.com'));
        $client = m::mock(EngineClientInterface::class);
        $client->shouldNotReceive('set');
        $client->shouldNotReceive('request');

        $this->expectException(UnsupportedTransportException::class);
        $this->expectExceptionMessage(
            'The configured PSR-17 response and stream factories are not supported.',
        );

        (new SwooleHandler)->send($client, $prepared, ['stream_factory' => new stdClass]);
    }

    /**
     * Prepare a request inside a coroutine.
     */
    private function prepareInCoroutine(
        RequestInterface $request,
        array $options = [],
    ): SwooleRequest|string {
        $result = null;

        run(function () use ($request, $options, &$result): void {
            $result = (new SwooleHandler)->prepare(
                $request,
                array_replace(['synchronous' => true], $options),
            );
        });

        return $result;
    }

    /**
     * Prepare a supported native request.
     */
    private function prepareNative(
        RequestInterface $request,
        array $options = [],
    ): SwooleRequest {
        $result = $this->prepareInCoroutine($request, $options);

        $this->assertInstanceOf(SwooleRequest::class, $result);

        return $result;
    }
}
