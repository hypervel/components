<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Engine\Exceptions\HttpClientBusyException;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Exceptions\InvalidArgumentException;
use Hypervel\Engine\Exceptions\RunningInNonCoroutineException;
use Hypervel\Engine\Exceptions\SocketClosedException;
use Hypervel\Engine\Exceptions\SocketConnectException;
use Hypervel\Engine\Exceptions\SocketTimeoutException;
use Hypervel\Engine\Http\Client;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\Http\Client as NativeHttpClient;
use Swoole\Coroutine\Http\Client\Exception as NativeHttpClientException;

class HttpClientTest extends TestCase
{
    public function testConstructionIsLazyAndForwardsTheEndpoint(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $client = new HttpClientTestClient($nativeClient, 'example.test', 8443, true);

        $this->assertSame(['example.test', 8443, true], $client->createdEndpoint);
        $this->assertFalse($client->isConnected());
        $this->assertSame([], $nativeClient->calls);
    }

    #[DataProvider('invalidEndpointProvider')]
    public function testConstructionRejectsInvalidEndpoints(string $host, int $port, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new Client($host, $port);
    }

    public static function invalidEndpointProvider(): array
    {
        return [
            'empty host' => ['', 80, 'host must not be empty'],
            'zero port' => ['example.test', 0, 'port must be between 1 and 65535'],
            'large port' => ['example.test', 65536, 'port must be between 1 and 65535'],
        ];
    }

    public function testConstructionWrapsNativeExceptions(): void
    {
        $cause = new NativeHttpClientException('native construction failed', 91);

        try {
            new HttpClientConstructionFailureTestClient($cause);
            $this->fail('Expected native construction to fail.');
        } catch (HttpClientException $exception) {
            $this->assertSame('native construction failed', $exception->getMessage());
            $this->assertSame(91, $exception->getCode());
            $this->assertSame($cause, $exception->getPrevious());
        }
    }

    public function testConstructionAndTransferSettingsAreAppliedInTheirOwnedPhases(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $client = new HttpClientTestClient($nativeClient, settings: [
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'ssl_cafile' => __FILE__,
            'ssl_capath' => __DIR__,
            'ssl_cert_file' => __FILE__,
            'ssl_key_file' => __FILE__,
            'ssl_host_name' => 'example.test',
            'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
        ]);
        $client->set([
            'connect_timeout' => 1,
            'timeout' => 2.5,
            'read_timeout' => 3,
            'body_decompression' => false,
        ]);

        $client->send();
        $nativeClient->completeResponse();
        $client->recv();
        $client->send();

        $this->assertSame([
            'ssl_verify_peer' => true,
            'ssl_allow_self_signed' => false,
            'ssl_cafile' => __FILE__,
            'ssl_capath' => __DIR__,
            'ssl_cert_file' => __FILE__,
            'ssl_key_file' => __FILE__,
            'ssl_host_name' => 'example.test',
            'ssl_protocols' => SWOOLE_SSL_TLSv1_2 | SWOOLE_SSL_TLSv1_3,
            'keep_alive' => true,
            'http_compression' => false,
            'lowercase_header' => false,
            'connect_timeout' => 1.0,
            'timeout' => 2.5,
            'read_timeout' => 3.0,
            'body_decompression' => false,
        ], $nativeClient->settings[0]);
        $this->assertSame([
            'keep_alive' => true,
            'http_compression' => false,
            'lowercase_header' => false,
            'connect_timeout' => 1.0,
            'timeout' => 2.5,
            'read_timeout' => 3.0,
            'body_decompression' => false,
        ], $nativeClient->settings[1]);
    }

    #[DataProvider('invalidConstructionSettingProvider')]
    public function testConstructionRejectsInvalidSettings(array $settings, string $message): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        new HttpClientTestClient(new HttpClientTestNativeClient, settings: $settings);
    }

    public static function invalidConstructionSettingProvider(): array
    {
        return [
            'unknown' => [['unknown' => true], '[unknown] is not supported'],
            'transfer' => [['timeout' => 1], '[timeout] must be configured through set()'],
            'boolean' => [['ssl_verify_peer' => 1], '[ssl_verify_peer] must be a boolean'],
            'file' => [['ssl_cafile' => '/missing/ca.pem'], '[ssl_cafile] must be a non-empty path to a readable file'],
            'directory' => [['ssl_capath' => __FILE__], '[ssl_capath] must be a non-empty path to a readable directory'],
            'host name' => [['ssl_host_name' => ''], '[ssl_host_name] must be a non-empty string'],
            'protocols type' => [['ssl_protocols' => 'tls1.2'], '[ssl_protocols] must be a non-zero combination of supported TLS protocol bits'],
            'protocols zero' => [['ssl_protocols' => 0], '[ssl_protocols] must be a non-zero combination of supported TLS protocol bits'],
            'protocols unsupported bit' => [['ssl_protocols' => SWOOLE_SSL_DTLS], '[ssl_protocols] must be a non-zero combination of supported TLS protocol bits'],
            'missing key' => [['ssl_cert_file' => __FILE__], '[ssl_cert_file] and [ssl_key_file] must be configured together'],
            'missing certificate' => [['ssl_key_file' => __FILE__], '[ssl_cert_file] and [ssl_key_file] must be configured together'],
        ];
    }

    #[DataProvider('invalidTransferSettingProvider')]
    public function testSetRejectsInvalidSettings(array $settings, string $message): void
    {
        $client = new HttpClientTestClient(new HttpClientTestNativeClient);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);

        $client->set($settings);
    }

    public static function invalidTransferSettingProvider(): array
    {
        return [
            'unknown' => [['unknown' => true], '[unknown] is not supported'],
            'fixed' => [['keep_alive' => false], '[keep_alive] is managed by the engine'],
            'construction' => [['ssl_verify_peer' => true], '[ssl_verify_peer] may only be configured during construction'],
            'protocols' => [['ssl_protocols' => SWOOLE_SSL_TLSv1_2], '[ssl_protocols] may only be configured during construction'],
            'body decompression' => [['body_decompression' => 1], '[body_decompression] must be a boolean'],
            'string timeout' => [['timeout' => '1'], '[timeout] must be a non-negative finite number'],
            'negative timeout' => [['connect_timeout' => -1], '[connect_timeout] must be a non-negative finite number'],
            'infinite timeout' => [['read_timeout' => INF], '[read_timeout] must be a non-negative finite number'],
        ];
    }

    public function testSendConfiguresAndResetsEveryMutableRequestValue(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $nativeClient->cookies = ['from' => 'previous-response'];
        $client = new HttpClientTestClient($nativeClient);

        $client->send(
            'POST',
            '/resources?active=1',
            ['X-Values' => ['one', 'two'], 'X-Single' => 'value'],
            'payload',
        );

        $this->assertSame([
            ['setDefer', true],
            ['setHeaders', ['X-Values' => 'one, two', 'X-Single' => 'value']],
            ['set', [
                'keep_alive' => true,
                'http_compression' => false,
                'lowercase_header' => false,
                'connect_timeout' => 0.0,
                'timeout' => 0.0,
                'read_timeout' => 0.0,
                'body_decompression' => true,
            ]],
            ['setMethod', 'POST'],
            ['setData', 'payload'],
            ['execute', '/resources?active=1'],
        ], $nativeClient->calls);
        $this->assertNull($nativeClient->cookies);
    }

    public function testRequestReturnsTruthfulHttp11ResponseData(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $nativeClient->responseStatusCode = 201;
        $nativeClient->responseHeaders = [
            'X-Single' => 'value',
            'Set-Cookie' => ['first=1', 'second=2'],
            'X-Repeated' => ['one', 'two'],
        ];
        $nativeClient->responseBody = "binary\0body";
        $client = new HttpClientTestClient($nativeClient);

        $response = $client->request('PUT', '/resource', version: '1.1');

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame([
            'X-Single' => ['value'],
            'Set-Cookie' => ['first=1', 'second=2'],
            'X-Repeated' => ['one', 'two'],
        ], $response->getHeaders());
        $this->assertSame("binary\0body", $response->getBody());
        $this->assertSame('1.1', $response->getVersion());
    }

    public function testOnlyHttp11IsAccepted(): void
    {
        $client = new HttpClientTestClient(new HttpClientTestNativeClient);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only supports HTTP/1.1');

        $client->send(version: '2.0');
    }

    public function testOnePendingResponseOwnsTheClientExclusively(): void
    {
        $client = new HttpClientTestClient(new HttpClientTestNativeClient);
        $client->send();

        try {
            $client->send();
            $this->fail('Expected overlapping send to fail.');
        } catch (HttpClientBusyException $exception) {
            $this->assertSame('The HTTP client already has a pending response.', $exception->getMessage());
        }

        $this->expectException(HttpClientBusyException::class);
        $this->expectExceptionMessage('cannot be configured while a response is pending');

        $client->set(['timeout' => 1]);
    }

    public function testRecvRequiresAPendingResponse(): void
    {
        $client = new HttpClientTestClient(new HttpClientTestNativeClient);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('no pending response');

        $client->recv();
    }

    #[DataProvider('invalidReceiveTimeoutProvider')]
    public function testRecvRejectsInvalidTimeouts(float $timeout): void
    {
        $client = new HttpClientTestClient(new HttpClientTestNativeClient);
        $client->send();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-negative finite number');

        $client->recv($timeout);
    }

    public static function invalidReceiveTimeoutProvider(): array
    {
        return [
            'negative' => [-0.1],
            'infinite' => [INF],
            'not a number' => [NAN],
        ];
    }

    #[DataProvider('transportFailureProvider')]
    public function testSendMapsNativeFailuresPrecisely(int $statusCode, int $errorCode, string $expected): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $nativeClient->executeResult = false;
        $nativeClient->statusCode = $statusCode;
        $nativeClient->errCode = $errorCode;
        $nativeClient->errMsg = 'native transport failed';
        $nativeClient->connected = true;
        $client = new HttpClientTestClient($nativeClient);

        try {
            $client->send();
            $this->fail('Expected the transfer to fail.');
        } catch (HttpClientException|SocketConnectException|SocketTimeoutException|SocketClosedException $exception) {
            $this->assertInstanceOf($expected, $exception);
            $this->assertSame('native transport failed', $exception->getMessage());
            $this->assertSame($errorCode, $exception->getCode());
            $this->assertSame(1, $nativeClient->closeCalls);
        }

        $nativeClient->executeResult = true;
        $client->send();
        $this->assertSame(2, $nativeClient->executeCalls);
    }

    public static function transportFailureProvider(): array
    {
        return [
            'connect refusal' => [SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED, SOCKET_ECONNREFUSED, SocketConnectException::class],
            'connect timeout' => [SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED, SOCKET_ETIMEDOUT, SocketConnectException::class],
            'TLS verification' => [SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED, SWOOLE_ERROR_SSL_VERIFY_FAILED, SocketConnectException::class],
            'TLS range unsatisfiable' => [SWOOLE_HTTP_CLIENT_ESTATUS_CONNECT_FAILED, SWOOLE_ERROR_SSL_HANDSHAKE_FAILED, SocketConnectException::class],
            'request timeout' => [SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT, SOCKET_ETIMEDOUT, SocketTimeoutException::class],
            'send timeout' => [SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED, SOCKET_ETIMEDOUT, SocketTimeoutException::class],
            'server reset' => [SWOOLE_HTTP_CLIENT_ESTATUS_SERVER_RESET, SOCKET_ECONNRESET, SocketClosedException::class],
            'lost peer' => [SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED, SOCKET_EPIPE, SocketClosedException::class],
            'unknown send failure' => [SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED, SOCKET_EINVAL, HttpClientException::class],
        ];
    }

    public function testReceiveFailureClearsPendingStateAndDiscardsTheConnection(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $nativeClient->receiveResult = false;
        $nativeClient->receiveStatusCode = SWOOLE_HTTP_CLIENT_ESTATUS_REQUEST_TIMEOUT;
        $nativeClient->receiveErrorCode = SOCKET_ETIMEDOUT;
        $nativeClient->receiveErrorMessage = 'read timed out';
        $client = new HttpClientTestClient($nativeClient);
        $client->send();

        try {
            $client->recv(0.25);
            $this->fail('Expected receive to fail.');
        } catch (SocketTimeoutException $exception) {
            $this->assertSame('read timed out', $exception->getMessage());
            $this->assertSame(1, $nativeClient->closeCalls);
        }

        $nativeClient->receiveResult = true;
        $client->send();
        $this->assertSame(2, $nativeClient->executeCalls);
    }

    public function testNativeExceptionIsPreservedAsTheTransportCause(): void
    {
        $cause = new NativeHttpClientException('native execute exception', 91);
        $nativeClient = new HttpClientTestNativeClient;
        $nativeClient->operationException = $cause;
        $nativeClient->exceptionOperation = 'execute';
        $nativeClient->statusCode = SWOOLE_HTTP_CLIENT_ESTATUS_SEND_FAILED;
        $nativeClient->errCode = SOCKET_EPIPE;
        $nativeClient->connected = true;
        $client = new HttpClientTestClient($nativeClient);

        try {
            $client->send();
            $this->fail('Expected execute to throw.');
        } catch (SocketClosedException $exception) {
            $this->assertSame($cause, $exception->getPrevious());
            $this->assertSame(1, $nativeClient->closeCalls);
        }
    }

    public function testConfigurationFailureDiscardsTheConnection(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $nativeClient->setResult = false;
        $nativeClient->errCode = SOCKET_EINVAL;
        $nativeClient->errMsg = 'invalid settings';
        $nativeClient->connected = true;
        $client = new HttpClientTestClient($nativeClient);

        try {
            $client->send();
            $this->fail('Expected configuration to fail.');
        } catch (HttpClientException $exception) {
            $this->assertSame('invalid settings', $exception->getMessage());
            $this->assertSame(SOCKET_EINVAL, $exception->getCode());
            $this->assertSame(1, $nativeClient->closeCalls);
        }
    }

    public function testCloseIsIdempotentAndClearsAPendingResponse(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $client = new HttpClientTestClient($nativeClient);
        $client->send();

        $client->close();
        $client->close();

        $this->assertSame(1, $nativeClient->closeCalls);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionMessage('no pending response');

        $client->recv();
    }

    public function testCloseFailureIsNormalized(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $nativeClient->connected = true;
        $nativeClient->closeResult = false;
        $nativeClient->errCode = SOCKET_EIO;
        $nativeClient->errMsg = 'close failed';
        $client = new HttpClientTestClient($nativeClient);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionCode(SOCKET_EIO);
        $this->expectExceptionMessage('close failed');

        $client->close();
    }

    public function testCompositionDoesNotExposeNativeClientMethods(): void
    {
        $this->assertFalse(method_exists(Client::class, 'setCookies'));
        $this->assertFalse(method_exists(Client::class, 'upgrade'));
        $this->assertFalse(is_subclass_of(Client::class, NativeHttpClient::class));
    }
}

class HttpClientNonCoroutineTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    public function testSendRejectsNonCoroutineUseBeforeNativeCalls(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $client = new HttpClientTestClient($nativeClient);

        try {
            $client->send();
            $this->fail('Expected send outside a coroutine to fail.');
        } catch (RunningInNonCoroutineException $exception) {
            $this->assertSame('HTTP client operations must run inside a coroutine.', $exception->getMessage());
            $this->assertSame([], $nativeClient->calls);
        }
    }

    public function testConnectedCloseRejectsNonCoroutineUseBeforeNativeCalls(): void
    {
        $nativeClient = new HttpClientTestNativeClient;
        $nativeClient->connected = true;
        $client = new HttpClientTestClient($nativeClient);

        try {
            $client->close();
            $this->fail('Expected close outside a coroutine to fail.');
        } catch (RunningInNonCoroutineException) {
            $this->assertSame(0, $nativeClient->closeCalls);
        }
    }
}

class HttpClientTestClient extends Client
{
    /** @var array{string, int, bool}|null */
    public ?array $createdEndpoint = null;

    public function __construct(
        private readonly HttpClientTestNativeClient $nativeClient,
        string $host = '127.0.0.1',
        int $port = 80,
        bool $ssl = false,
        array $settings = [],
    ) {
        parent::__construct($host, $port, $ssl, $settings);
    }

    protected function createNativeClient(string $host, int $port, bool $ssl): NativeHttpClient
    {
        $this->createdEndpoint = [$host, $port, $ssl];

        return $this->nativeClient;
    }
}

class HttpClientConstructionFailureTestClient extends Client
{
    public function __construct(private readonly NativeHttpClientException $exception)
    {
        parent::__construct('127.0.0.1');
    }

    protected function createNativeClient(string $host, int $port, bool $ssl): NativeHttpClient
    {
        throw $this->exception;
    }
}

class HttpClientTestNativeClient extends NativeHttpClient
{
    /** @var list<array{0: string, 1?: mixed}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> */
    public array $settings = [];

    public bool $setResult = true;

    public bool $executeResult = true;

    public bool $receiveResult = true;

    public bool $closeResult = true;

    public int $executeCalls = 0;

    public int $closeCalls = 0;

    public int $responseStatusCode = 200;

    /** @var array<string, string|string[]> */
    public array $responseHeaders = [];

    public string $responseBody = '';

    public int $receiveStatusCode = 200;

    public int $receiveErrorCode = 0;

    public string $receiveErrorMessage = '';

    public ?string $exceptionOperation = null;

    public ?NativeHttpClientException $operationException = null;

    public function __construct()
    {
        $this->connected = false;
        $this->errCode = 0;
        $this->errMsg = '';
        $this->statusCode = 0;
        $this->headers = null;
        $this->cookies = null;
        $this->body = '';
    }

    public function set(array $settings): bool
    {
        $this->throwFor('set');
        $this->calls[] = ['set', $settings];
        $this->settings[] = $settings;

        return $this->setResult;
    }

    public function setDefer(bool $defer = true): bool
    {
        $this->throwFor('setDefer');
        $this->calls[] = ['setDefer', $defer];

        return true;
    }

    public function setMethod(string $method): bool
    {
        $this->throwFor('setMethod');
        $this->calls[] = ['setMethod', $method];

        return true;
    }

    public function setHeaders(array $headers): bool
    {
        $this->throwFor('setHeaders');
        $this->calls[] = ['setHeaders', $headers];

        return true;
    }

    public function setData(array|string $data): bool
    {
        $this->throwFor('setData');
        $this->calls[] = ['setData', $data];

        return true;
    }

    public function execute(string $path): bool
    {
        $this->throwFor('execute');
        $this->calls[] = ['execute', $path];
        ++$this->executeCalls;

        if ($this->executeResult) {
            $this->connected = true;
        }

        return $this->executeResult;
    }

    public function recv(float $timeout = 0): bool
    {
        $this->throwFor('recv');
        $this->calls[] = ['recv', $timeout];

        if (! $this->receiveResult) {
            $this->statusCode = $this->receiveStatusCode;
            $this->errCode = $this->receiveErrorCode;
            $this->errMsg = $this->receiveErrorMessage;

            return false;
        }

        $this->completeResponse();

        return true;
    }

    public function close(): bool
    {
        $this->throwFor('close');
        $this->calls[] = ['close'];
        ++$this->closeCalls;

        if ($this->closeResult) {
            $this->connected = false;
        }

        return $this->closeResult;
    }

    public function completeResponse(): void
    {
        $this->statusCode = $this->responseStatusCode;
        $this->headers = $this->responseHeaders;
        $this->body = $this->responseBody;
        $this->errCode = 0;
        $this->errMsg = '';
    }

    private function throwFor(string $operation): void
    {
        if ($this->exceptionOperation === $operation && $this->operationException !== null) {
            throw $this->operationException;
        }
    }
}
