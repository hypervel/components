<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\BedrockRuntime\Exception\BedrockRuntimeException;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Exception\ResponseTransferException;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\TransferStats;
use Hypervel\Ai\AiManager;
use Hypervel\Ai\Gateway\Bedrock\BedrockHttpHandler;
use Hypervel\Http\Client\Factory;
use Hypervel\Http\Client\Request as HttpRequest;
use Hypervel\Tests\Http\Fixtures\LoopbackHttpServer;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Swoole\Coroutine\CanceledException;

class BedrockHttpHandlerTest extends TestCase
{
    public function testSignedRequestAndAwsOptionsReachTheFrameworkClient(): void
    {
        $http = (new Factory)->registerConnection(AiManager::HTTP_CONNECTION);
        $request = new Request('POST', 'https://bedrock.test/model/example/invoke', [
            'Authorization' => 'AWS4-HMAC-SHA256 signed-request',
            'X-Amz-Security-Token' => 'session-token',
            'Content-Type' => 'application/json',
        ], '{"input":"hello"}', '1.0');
        $seen = null;
        $http->fake(function (HttpRequest $outgoing, array $options) use (&$seen): PromiseInterface {
            $seen = [$outgoing->toPsrRequest(), $options];

            return Factory::response('result');
        });

        $response = (new BedrockHttpHandler($http))($request, ['timeout' => 12, 'delay' => 150])->wait();

        $this->assertSame('result', (string) $response->getBody());
        $this->assertSame($request->getMethod(), $seen[0]->getMethod());
        $this->assertSame((string) $request->getUri(), (string) $seen[0]->getUri());
        $this->assertSame($request->getProtocolVersion(), $seen[0]->getProtocolVersion());
        $this->assertSame($request->getHeaderLine('Authorization'), $seen[0]->getHeaderLine('Authorization'));
        $this->assertSame('session-token', $seen[0]->getHeaderLine('X-Amz-Security-Token'));
        $this->assertSame($request->getBody(), $seen[0]->getBody());
        $this->assertSame(12, $seen[1]['timeout']);
        $this->assertSame(150, $seen[1]['delay']);
    }

    #[DataProvider('httpErrorOptions')]
    public function testAwsParsesHttpErrorsInsteadOfTreatingThemAsSuccessfulResults(bool $httpErrors): void
    {
        $http = (new Factory)->registerConnection(AiManager::HTTP_CONNECTION, ['http_errors' => $httpErrors]);
        $http->fake(fn (): PromiseInterface => Factory::response(
            ['message' => 'Too many requests'],
            429,
            ['x-amzn-errortype' => 'ThrottlingException'],
        ));
        $caught = null;

        try {
            $this->client($http)->invokeModel(['modelId' => 'test-model', 'body' => '{}']);
        } catch (BedrockRuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(BedrockRuntimeException::class, $caught);
        $this->assertSame('ThrottlingException', $caught->getAwsErrorCode());
        $this->assertSame(429, $caught->getStatusCode());
        $this->assertFalse($caught->isConnectionError());
    }

    /**
     * Provide both supported HTTP error modes.
     */
    public static function httpErrorOptions(): array
    {
        return [[false], [true]];
    }

    public function testAwsRetriesServerErrorsAndPassesItsDelayToHttp(): void
    {
        $http = (new Factory)->registerConnection(AiManager::HTTP_CONNECTION);
        $delays = [];
        $http->fake(function (HttpRequest $request, array $options) use (&$delays): PromiseInterface {
            $delays[] = $options['delay'] ?? null;

            return count($delays) === 1
                ? Factory::response(['message' => 'Try again'], 503, ['x-amzn-errortype' => 'ServiceUnavailableException'])
                : Factory::response('result');
        });

        $response = $this->client($http, 1)->invokeModel(['modelId' => 'test-model', 'body' => '{}']);

        $this->assertSame('result', (string) $response['body']);
        $this->assertCount(2, $delays);
        $this->assertIsNumeric($delays[1]);
    }

    #[DataProvider('responsePresence')]
    public function testAwsRecognizesTransportConnectionFailures(bool $hasResponse): void
    {
        $http = (new Factory)->registerConnection(AiManager::HTTP_CONNECTION);
        $response = $hasResponse ? new Response(200, [], 'partial') : null;
        $http->fake($hasResponse ? static function (HttpRequest $request) use ($response): never {
            throw class_exists(ResponseTransferException::class)
                ? new ResponseTransferException('Transfer interrupted', $request->toPsrRequest(), $response)
                : new RequestException('Transfer interrupted', $request->toPsrRequest(), $response, null, ['errno' => 56]);
        } : Factory::failedConnection('Connection refused'));
        $caught = null;

        try {
            $this->client($http)->invokeModel(['modelId' => 'test-model', 'body' => '{}']);
        } catch (BedrockRuntimeException $exception) {
            $caught = $exception;
        }

        $this->assertInstanceOf(BedrockRuntimeException::class, $caught);
        $this->assertTrue($caught->isConnectionError());
        $this->assertSame($response, $caught->getResponse());
    }

    /**
     * Provide failures before and after receiving response headers.
     */
    public static function responsePresence(): array
    {
        return [[false], [true]];
    }

    public function testCancellationPassesThroughTheSdkUnchanged(): void
    {
        $http = (new Factory)->registerConnection(AiManager::HTTP_CONNECTION);
        $cancellation = new CanceledException('Request canceled.');
        $http->fake(static fn (): never => throw $cancellation);
        $caught = null;

        try {
            $this->client($http)->converse([
                'modelId' => 'test-model',
                'messages' => [['role' => 'user', 'content' => [['text' => 'Hello']]]],
            ]);
        } catch (CanceledException $exception) {
            $caught = $exception;
        }

        $this->assertSame($cancellation, $caught);
    }

    #[DataProvider('statsOverrides')]
    public function testAwsStatisticsPreserveTheEffectiveUserCallback(bool $override): void
    {
        $server = LoopbackHttpServer::start([['body' => 'result']]);
        $http = (new Factory)->registerConnection(AiManager::HTTP_CONNECTION);
        $frameworkStats = null;
        $requestStats = null;
        $awsStats = null;
        $http->globalOptions(['on_stats' => static function (TransferStats $stats) use (&$frameworkStats): void {
            $frameworkStats = $stats;
        }]);
        $options = ['http_stats_receiver' => static function (array $stats) use (&$awsStats): void {
            $awsStats = $stats;
        }];

        if ($override) {
            $options['on_stats'] = static function (TransferStats $stats) use (&$requestStats): void {
                $requestStats = $stats;
            };
        }

        $response = null;

        try {
            $response = (new BedrockHttpHandler($http))(
                new Request('GET', 'http://127.0.0.1:' . $server->port),
                $options,
            )->wait();
            $this->assertSame('result', (string) $response->getBody());
            $effectiveStats = $override ? $requestStats : $frameworkStats;
            $this->assertInstanceOf(TransferStats::class, $effectiveStats);
            $this->assertNull($override ? $frameworkStats : $requestStats);
            $this->assertSame($effectiveStats->getTransferTime(), $awsStats['total_time']);
            $this->assertSame(200, $awsStats['http_code']);
        } finally {
            $response?->getBody()->close();
            $server->request();
        }
    }

    /**
     * Provide inherited and overridden user statistics callbacks.
     */
    public static function statsOverrides(): array
    {
        return [[false], [true]];
    }

    /**
     * Create an SDK client using the framework transport.
     */
    protected function client(Factory $http, int $retries = 0): BedrockRuntimeClient
    {
        return new BedrockRuntimeClient([
            'region' => 'us-east-1',
            'version' => '2023-09-30',
            'credentials' => ['key' => 'test', 'secret' => 'test'],
            'retries' => $retries,
            'http_handler' => new BedrockHttpHandler($http),
        ]);
    }
}
