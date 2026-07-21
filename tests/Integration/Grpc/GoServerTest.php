<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Grpc;

use Google\Rpc\ErrorInfo;
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Grpc\Compression;
use Hypervel\Grpc\Exceptions\ConnectionException;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Health\V1\HealthCheckRequest;
use Hypervel\Grpc\Health\V1\HealthCheckResponse\ServingStatus;
use Hypervel\Grpc\Health\V1\HealthListRequest;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\Grpc\Fixtures\TestReply;
use Hypervel\Tests\Grpc\Fixtures\TestRequest;
use RuntimeException;

use function Hypervel\Coroutine\parallel;

class GoServerTest extends GrpcIntegrationTestCase
{
    protected int $serverPort = 19521;

    public function testAllFourCallShapesAndMetadataInteroperate(): void
    {
        $client = $this->newTestClient(['timeout' => 2.0]);
        $unary = $client->unary(
            (new TestRequest)->setValue('hello'),
            [
                'x-echo' => ['one', 'two'],
                'echo-bin' => "\x00\x01",
            ],
        );

        $this->assertSame('unary:hello', $unary->wait()->getValue());
        $this->assertCount(2, $unary->metadata());
        $this->assertSame(['grpc-go'], $unary->metadata()->values('x-test-peer'));
        $this->assertSame(['one,two'], $unary->metadata()->values('x-echo'));
        $this->assertCount(2, $unary->trailers());
        $this->assertSame(['grpc-go'], $unary->trailers()->values('x-test-trailer'));
        $this->assertSame(["\x00\x01"], $unary->trailers()->values('echo-bin'));

        $serverStream = $client->serverStream((new TestRequest)->setValue('hello'));
        $this->assertSame(
            ['stream:hello:1', 'stream:hello:2', 'stream:hello:3'],
            array_map(
                static fn (TestReply $reply): string => $reply->getValue(),
                iterator_to_array($serverStream->responses(), false),
            ),
        );
        $this->assertSame(['x-test-peer' => ['grpc-go']], $serverStream->metadata()->all());
        $this->assertSame(['x-test-trailer' => ['grpc-go']], $serverStream->trailers()->all());

        $clientStream = $client->clientStream();
        $clientStream->write((new TestRequest)->setValue('one'));
        $clientStream->write((new TestRequest)->setValue('two'));
        $clientStream->write((new TestRequest)->setValue('three'));
        $clientStream->writesDone();
        $this->assertSame('client:one,two,three', $clientStream->wait()->getValue());
        $this->assertSame(['x-test-peer' => ['grpc-go']], $clientStream->metadata()->all());
        $this->assertSame(['x-test-trailer' => ['grpc-go']], $clientStream->trailers()->all());

        $bidi = $client->bidiStream();
        $bidi->write((new TestRequest)->setValue('one'));
        $this->assertSame('bidi:one', $bidi->read()?->getValue());
        $bidi->write((new TestRequest)->setValue('two'));
        $bidi->writesDone();
        $this->assertSame('bidi:two', $bidi->read()?->getValue());
        $this->assertNull($bidi->read());
        $this->assertSame(['x-test-peer' => ['grpc-go']], $bidi->metadata()->all());
        $this->assertSame(['x-test-trailer' => ['grpc-go']], $bidi->trailers()->all());
    }

    public function testIdentityAndGzipCompressionInteroperateInBothDirections(): void
    {
        $client = $this->newTestClient([
            'timeout' => 2.0,
            'compression' => Compression::Gzip,
        ]);

        $this->assertSame(
            'unary:request-compressed',
            $client->unary((new TestRequest)->setValue('request-compressed'))->wait()->getValue(),
        );
        $this->assertSame(
            'unary:response-compressed',
            $client->unary((new TestRequest)->setValue('gzip:response-compressed'))->wait()->getValue(),
        );

        $stream = $client->serverStream((new TestRequest)->setValue('gzip:response-compressed'));
        $this->assertSame(
            [
                'stream:response-compressed:1',
                'stream:response-compressed:2',
                'stream:response-compressed:3',
            ],
            array_map(
                static fn (TestReply $reply): string => $reply->getValue(),
                iterator_to_array($stream->responses(), false),
            ),
        );
    }

    public function testStandardAndRichErrorsInteroperate(): void
    {
        $client = $this->newTestClient(['timeout' => 2.0]);

        try {
            $client->unary((new TestRequest)->setValue('error:not-found'))->wait();
            $this->fail('Expected the standard grpc-go status.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::NotFound, $exception->status()->code());
            $this->assertSame('The requested test value was not found.', $exception->getMessage());
        }

        try {
            $client->unary((new TestRequest)->setValue('error:rich'))->wait();
            $this->fail('Expected the rich grpc-go status.');
        } catch (RpcException $exception) {
            $details = $exception->status()->details();

            $this->assertNotNull($details);
            $this->assertSame(StatusCode::InvalidArgument->value, $details->getCode());
            $this->assertSame('The test value is invalid.', $details->getMessage());
            $this->assertCount(1, $details->getDetails());
            $any = $details->getDetails()[0];
            $this->assertSame('type.googleapis.com/google.rpc.ErrorInfo', $any->getTypeUrl());
            $detail = new ErrorInfo;
            $detail->mergeFromString($any->getValue());
            $this->assertSame('INVALID_TEST_VALUE', $detail->getReason());
            $this->assertSame('hypervel.dev', $detail->getDomain());
            $this->assertSame('error:rich', $detail->getMetadata()['value']);
        }
    }

    public function testDeadlineRetiresOnlyTheExpiredStreamAndReconnects(): void
    {
        $client = $this->newTestClient();

        try {
            $client->unary(
                (new TestRequest)->setValue('delay:200ms'),
                options: ['timeout' => 0.05],
            )->wait();
            $this->fail('Expected the grpc-go call deadline to expire.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::DeadlineExceeded, $exception->status()->code());
        }

        $this->assertSame(
            'unary:reconnected',
            $client->unary(
                (new TestRequest)->setValue('reconnected'),
                options: ['timeout' => 2.0],
            )->wait()->getValue(),
        );
    }

    public function testMergedHeaderOnlyRetryBehaviorMatchesTheDocumentedSwooleBoundary(): void
    {
        $client = $this->newTestClient(['timeout' => 2.0]);
        $retry = new RetryPolicy(
            maxAttempts: 2,
            initialBackoff: 0.001,
            maxBackoff: 0.001,
        );
        $call = $client->unary(
            (new TestRequest)->setValue('retry:merged'),
            options: ['retry' => $retry],
        );

        $this->assertSame('retried:1', $call->wait()->getValue());
        $this->assertSame(['x-test-peer' => ['grpc-go']], $call->metadata()->all());
        $this->assertSame(['x-test-trailer' => ['grpc-go']], $call->trailers()->all());
    }

    public function testStandardHealthCheckListAndWatchInteroperate(): void
    {
        $client = $this->newHealthClient(['timeout' => 2.0]);

        $check = $client->check(new HealthCheckRequest)->wait();
        $this->assertSame(ServingStatus::SERVING, $check->getStatus());

        $statuses = $client->list(new HealthListRequest)->wait()->getStatuses();
        $this->assertSame(ServingStatus::SERVING, $statuses['']->getStatus());
        $this->assertSame(ServingStatus::NOT_SERVING, $statuses['testing']->getStatus());

        try {
            $client->check((new HealthCheckRequest)->setService('missing'))->wait();
            $this->fail('Expected grpc-go health to reject an unknown service.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::NotFound, $exception->status()->code());
        }

        $watch = $client->watch((new HealthCheckRequest)->setService('testing'));
        $this->assertSame(ServingStatus::NOT_SERVING, $watch->read()?->getStatus());
    }

    public function testConnectionLossWakesEveryStreamAndTheNextCallReconnects(): void
    {
        $client = $this->newTestClient(['timeout' => 2.0]);
        $slow = $client->unary((new TestRequest)->setValue('delay:500ms'));
        $disconnect = $client->unary((new TestRequest)->setValue('disconnect'));
        $failures = parallel([
            static function () use ($slow): ConnectionException {
                try {
                    $slow->wait();
                } catch (ConnectionException $exception) {
                    return $exception;
                }

                throw new RuntimeException('The slow call unexpectedly completed.');
            },
            static function () use ($disconnect): ConnectionException {
                try {
                    $disconnect->wait();
                } catch (ConnectionException $exception) {
                    return $exception;
                }

                throw new RuntimeException('The disconnecting call unexpectedly completed.');
            },
        ]);

        $this->assertContainsOnlyInstancesOf(ConnectionException::class, $failures);
        $this->assertSame(
            'unary:reconnected',
            $client->unary((new TestRequest)->setValue('reconnected'))->wait()->getValue(),
        );
    }

    public function testMultiplexesOneHundredCallsAgainstGrpcGo(): void
    {
        $client = $this->newTestClient(['timeout' => 3.0]);
        $calls = [];

        for ($index = 0; $index < 100; ++$index) {
            $calls[$index] = static function () use ($client, $index): string {
                $value = $index % 10 === 0 ? 'delay:50ms' : "value-{$index}";

                return $client->unary((new TestRequest)->setValue($value))->wait()->getValue();
            };
        }

        $responses = parallel($calls);

        foreach ($responses as $index => $response) {
            $this->assertSame(
                $index % 10 === 0 ? 'delayed' : "unary:value-{$index}",
                $response,
            );
        }
    }
}
