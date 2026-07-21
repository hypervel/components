<?php

declare(strict_types=1);

namespace Hypervel\Tests\Engine;

use Hypervel\Contracts\Engine\Http\V2\ClientInterface;
use Hypervel\Engine\Exceptions\HttpClientException;
use Hypervel\Engine\Http\V2\Client;
use Hypervel\Engine\Http\V2\ClientFactory;
use Hypervel\Engine\Http\V2\Request;
use Hypervel\Engine\Http\V2\Response;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Swoole\Coroutine\Http2\Client as NativeClient;
use Swoole\Coroutine\Http2\Client\Exception as NativeHttp2Exception;
use Swoole\Http2\Request as NativeRequest;
use Swoole\Http2\Response as NativeResponse;

class Http2ClientTest extends TestCase
{
    public function testConstructionRejectsAFailedConnection(): void
    {
        $this->expectException(HttpClientException::class);

        new Client('127.0.0.1', 0);
    }

    public function testConstructionWrapsNativeHttp2Exceptions(): void
    {
        try {
            new Client('');
            $this->fail('Expected native construction to fail.');
        } catch (HttpClientException $exception) {
            $this->assertInstanceOf(NativeHttp2Exception::class, $exception->getPrevious());
            $this->assertSame('host is empty', $exception->getMessage());
        }
    }

    public function testRequestPipelineAndResponseReadFlagsAreIndependent(): void
    {
        $request = new Request(pipeline: true, usePipelineRead: false);

        $this->assertTrue($request->isPipeline());
        $this->assertFalse($request->usesPipelineRead());

        $request->setPipeline(false);
        $request->setUsePipelineRead(true);

        $this->assertFalse($request->isPipeline());
        $this->assertTrue($request->usesPipelineRead());
    }

    public function testSendCopiesIndependentPipelineFlagsToTheNativeRequest(): void
    {
        $native = new Http2ClientTestNativeClient;
        $client = Http2ClientTestClient::fromNative($native);

        $client->send(new Request(
            path: '/example',
            method: 'POST',
            body: 'body',
            headers: ['x-test' => 'value'],
            pipeline: false,
            usePipelineRead: true,
        ));

        $this->assertNotNull($native->lastRequest);
        $this->assertSame('/example', $native->lastRequest->path);
        $this->assertSame('POST', $native->lastRequest->method);
        $this->assertSame('body', $native->lastRequest->data);
        $this->assertSame(['x-test' => 'value'], $native->lastRequest->headers);
        $this->assertFalse($native->lastRequest->pipeline);
        $this->assertTrue($native->lastRequest->usePipelineRead);
    }

    public function testSendAndWriteApplyTheirOperationWriteTimeout(): void
    {
        $native = new Http2ClientTestNativeClient;
        $client = Http2ClientTestClient::fromNative($native);

        $client->send(new Request, 0.125);
        $client->write(1, 'frame', true, 0.25);

        $this->assertSame([
            ['write_timeout' => 0.125],
            ['write_timeout' => 0.25],
        ], $native->appliedSettings);
    }

    public function testSendAndWriteDoNotMutateSettingsWithoutAnOperationTimeout(): void
    {
        $native = new Http2ClientTestNativeClient;
        $client = Http2ClientTestClient::fromNative($native);

        $client->send(new Request);
        $client->write(1, 'frame');

        $this->assertSame([], $native->appliedSettings);
    }

    public function testFailedOperationTimeoutConfigurationBecomesAnHttpClientException(): void
    {
        $native = new Http2ClientTestNativeClient;
        $native->setResult = false;
        $native->errCode = 22;
        $native->errMsg = 'invalid write timeout';
        $client = Http2ClientTestClient::fromNative($native);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionCode(22);
        $this->expectExceptionMessage('invalid write timeout');

        $client->send(new Request, 0.1);
    }

    #[DataProvider('invalidOperationWriteTimeoutProvider')]
    public function testOperationWriteTimeoutMustBePositiveAndFinite(float $timeout): void
    {
        $client = Http2ClientTestClient::fromNative(new Http2ClientTestNativeClient);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('positive finite');

        $client->send(new Request, $timeout);
    }

    public static function invalidOperationWriteTimeoutProvider(): array
    {
        return [
            'zero' => [0.0],
            'negative' => [-1.0],
            'infinite' => [INF],
            'not a number' => [NAN],
        ];
    }

    public function testResponseRetainsWhetherTheNativeEventEndsTheStream(): void
    {
        $this->assertFalse((new Response(1, 200, [], 'body', true))->isEndStream());
        $this->assertTrue((new Response(1, 200, [], 'body', false))->isEndStream());

        $nativeResponse = new NativeResponse;
        $nativeResponse->streamId = 3;
        $nativeResponse->statusCode = 200;
        $nativeResponse->headers = ['x-test' => 'value'];
        $nativeResponse->data = 'body';
        $nativeResponse->pipeline = true;
        $native = new Http2ClientTestNativeClient;
        $native->receiveResult = $nativeResponse;
        $response = Http2ClientTestClient::fromNative($native)->recv();

        $this->assertNotNull($response);
        $this->assertSame(3, $response->getStreamId());
        $this->assertFalse($response->isEndStream());
    }

    public function testReceiveTimeoutReturnsNullAndOtherFailuresThrow(): void
    {
        $native = new Http2ClientTestNativeClient;
        $native->errCode = SOCKET_ETIMEDOUT;
        $native->errMsg = 'timed out';
        $client = Http2ClientTestClient::fromNative($native);

        $this->assertNull($client->recv(0.1));

        $native->errCode = 111;
        $native->errMsg = 'connection refused';

        try {
            $client->recv(0.1);
            $this->fail('Expected a non-timeout receive failure.');
        } catch (HttpClientException $exception) {
            $this->assertSame(111, $exception->getCode());
            $this->assertSame('connection refused', $exception->getMessage());
        }
    }

    public function testWriteFalseBecomesAnHttpClientException(): void
    {
        $native = new Http2ClientTestNativeClient;
        $native->writeResult = false;
        $native->errCode = 32;
        $native->errMsg = 'broken pipe';
        $client = Http2ClientTestClient::fromNative($native);

        $this->expectException(HttpClientException::class);
        $this->expectExceptionCode(32);
        $this->expectExceptionMessage('broken pipe');

        $client->write(1, 'frame');
    }

    public function testExposesNormalizedStreamExistence(): void
    {
        $native = new Http2ClientTestNativeClient;
        $native->streamOpen = true;
        $client = Http2ClientTestClient::fromNative($native);

        $this->assertTrue($client->isStreamOpen(3));

        $native->streamOpen = false;

        $this->assertFalse($client->isStreamOpen(3));
    }

    public function testCloseIsVoidAndIdempotent(): void
    {
        $native = new Http2ClientTestNativeClient;
        $client = Http2ClientTestClient::fromNative($native);

        $client->close();
        $client->close();

        $this->assertSame(1, $native->closeCalls);
        $this->assertFalse($client->isConnected());
    }

    public function testActiveCloseFailureThrows(): void
    {
        $native = new Http2ClientTestNativeClient;
        $native->closeResult = false;
        $native->errCode = 5;
        $native->errMsg = 'close failed';

        $this->expectException(HttpClientException::class);
        $this->expectExceptionCode(5);
        $this->expectExceptionMessage('close failed');

        Http2ClientTestClient::fromNative($native)->close();
    }

    public function testWrapsNativeOperationExceptionsWithTheirCause(): void
    {
        foreach (['send', 'recv', 'write', 'stream', 'close'] as $operation) {
            $native = new Http2ClientTestNativeClient;
            $cause = new NativeHttp2Exception("{$operation} failed", 91);
            $native->exceptionOperation = $operation;
            $native->operationException = $cause;
            $client = Http2ClientTestClient::fromNative($native);

            try {
                match ($operation) {
                    'send' => $client->send(new Request),
                    'recv' => $client->recv(),
                    'write' => $client->write(1, 'frame'),
                    'stream' => $client->isStreamOpen(1),
                    'close' => $client->close(),
                };
                $this->fail("Expected native {$operation} failure to be wrapped.");
            } catch (HttpClientException $exception) {
                $this->assertSame("{$operation} failed", $exception->getMessage());
                $this->assertSame(91, $exception->getCode());
                $this->assertSame($cause, $exception->getPrevious());
            }
        }
    }

    public function testContractsAndConcreteClientOmitPostConnectSettingsAndPing(): void
    {
        foreach ([ClientInterface::class, Client::class] as $class) {
            $this->assertFalse(method_exists($class, 'set'));
            $this->assertFalse(method_exists($class, 'ping'));
        }

        $factoryMethod = (new ReflectionClass(ClientFactory::class))->getMethod('make');

        $this->assertCount(4, $factoryMethod->getParameters());
        $this->assertSame('settings', $factoryMethod->getParameters()[3]->getName());
    }
}

class Http2ClientTestClient extends Client
{
    public static function fromNative(Http2ClientTestNativeClient $native): self
    {
        $client = (new ReflectionClass(self::class))->newInstanceWithoutConstructor();
        $client->client = $native;

        return $client;
    }
}

class Http2ClientTestNativeClient extends NativeClient
{
    public ?NativeRequest $lastRequest = null;

    public int|false $sendResult = 1;

    public NativeResponse|false $receiveResult = false;

    public bool $writeResult = true;

    public bool $streamOpen = false;

    public bool $closeResult = true;

    public bool $setResult = true;

    /** @var list<array<string, mixed>> */
    public array $appliedSettings = [];

    public int $closeCalls = 0;

    public ?string $exceptionOperation = null;

    public ?NativeHttp2Exception $operationException = null;

    public function __construct()
    {
        $this->connected = true;
        $this->errCode = 0;
        $this->errMsg = '';
    }

    public function send(NativeRequest $request): int|false
    {
        $this->throwFor('send');
        $this->lastRequest = $request;

        return $this->sendResult;
    }

    public function set(array $settings): bool
    {
        $this->throwFor('set');
        $this->appliedSettings[] = $settings;

        return $this->setResult;
    }

    public function recv(float $timeout = 0): NativeResponse|false
    {
        $this->throwFor('recv');

        return $this->receiveResult;
    }

    public function write(int $streamId, mixed $data, bool $endStream = false): bool
    {
        $this->throwFor('write');

        return $this->writeResult;
    }

    public function isStreamExist(int $streamId): bool
    {
        $this->throwFor('stream');

        return $this->streamOpen;
    }

    public function close(): bool
    {
        $this->throwFor('close');
        ++$this->closeCalls;

        if ($this->closeResult) {
            $this->connected = false;
        }

        return $this->closeResult;
    }

    private function throwFor(string $operation): void
    {
        if ($this->exceptionOperation === $operation && $this->operationException !== null) {
            throw $this->operationException;
        }
    }
}
