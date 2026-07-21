<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Grpc;

use Google\Rpc\ErrorInfo;
use Hypervel\Grpc\Client\RetryPolicy;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Health\V1\HealthCheckRequest;
use Hypervel\Grpc\Health\V1\HealthCheckResponse\ServingStatus;
use Hypervel\Grpc\Health\V1\HealthListRequest;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\Grpc\Fixtures\TestReply;
use Hypervel\Tests\Grpc\Fixtures\TestRequest;

use function Hypervel\Coroutine\parallel;

class HypervelServerTest extends GrpcIntegrationTestCase
{
    public function testUnaryCallPreservesExactInitialAndTrailingMetadata(): void
    {
        $client = $this->newTestClient(['timeout' => 2.0]);
        $call = $client->unary(
            (new TestRequest)->setValue('hello'),
            [
                'x-echo' => ['one', 'two'],
                'echo-bin' => "\x00\x01",
            ],
        );

        $reply = $call->wait();

        $this->assertInstanceOf(TestReply::class, $reply);
        $this->assertSame('unary:hello', $reply->getValue());
        $this->assertSame([
            'x-test-peer' => ['hypervel'],
            'x-echo' => ['one,two'],
        ], $call->metadata()->all());
        $this->assertSame([
            'x-test-trailer' => ['hypervel'],
            'echo-bin' => ["\x00\x01"],
        ], $call->trailers()->all());
        $this->assertSame(StatusCode::Ok, $call->status()->code());
        $this->assertSame('127.0.0.1:19520', $call->peer());
    }

    public function testServerStreamingSupportsSuccessEmptyAndMidStreamFailure(): void
    {
        $client = $this->newTestClient(['timeout' => 2.0]);
        $success = $client->serverStream((new TestRequest)->setValue('hello'));

        $this->assertSame(
            ['stream:hello:1', 'stream:hello:2', 'stream:hello:3'],
            array_map(
                static fn (TestReply $reply): string => $reply->getValue(),
                iterator_to_array($success->responses(), false),
            ),
        );
        $this->assertSame(['x-test-peer' => ['hypervel']], $success->metadata()->all());
        $this->assertSame(['x-test-trailer' => ['hypervel']], $success->trailers()->all());

        $empty = $client->serverStream((new TestRequest)->setValue('empty'));
        $this->assertSame([], iterator_to_array($empty->responses(), false));
        $this->assertSame([], $empty->metadata()->all());
        $this->assertSame([
            'x-test-peer' => ['hypervel'],
            'x-test-trailer' => ['hypervel'],
        ], $empty->trailers()->all());

        $partial = $client->serverStream((new TestRequest)->setValue('partial-error'));
        $this->assertSame('stream:partial:1', $partial->read()?->getValue());

        try {
            $partial->read();
            $this->fail('Expected the mid-stream status to be reported after the delivered message.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::NotFound, $exception->status()->code());
            $this->assertSame('The stream failed after a response.', $exception->getMessage());
            $this->assertSame('hypervel', $exception->trailers()->first('x-error-source'));
        }
    }

    public function testStandardHealthServiceImplementsCheckListAndWatchFallback(): void
    {
        $client = $this->newHealthClient(['timeout' => 2.0]);

        $check = $client->check(new HealthCheckRequest)->wait();
        $this->assertSame(ServingStatus::SERVING, $check->getStatus());

        $named = $client->check((new HealthCheckRequest)->setService('testing'))->wait();
        $this->assertSame(ServingStatus::NOT_SERVING, $named->getStatus());

        $statuses = $client->list(new HealthListRequest)->wait()->getStatuses();
        $this->assertSame(ServingStatus::SERVING, $statuses['']->getStatus());
        $this->assertSame(ServingStatus::NOT_SERVING, $statuses['testing']->getStatus());

        try {
            $client->check((new HealthCheckRequest)->setService('missing'))->wait();
            $this->fail('Expected an unknown service to return NotFound.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::NotFound, $exception->status()->code());
        }

        try {
            $client->watch(new HealthCheckRequest)->read();
            $this->fail('Expected the platform-correct health Watch fallback.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::Unimplemented, $exception->status()->code());
        }
    }

    public function testStandardAndRichErrorsRemainTyped(): void
    {
        $client = $this->newTestClient(['timeout' => 2.0]);

        try {
            $client->unary((new TestRequest)->setValue('error:not-found'))->wait();
            $this->fail('Expected the standard RPC error.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::NotFound, $exception->status()->code());
            $this->assertSame('hypervel', $exception->trailers()->first('x-error-source'));
        }

        try {
            $client->unary((new TestRequest)->setValue('error:rich'))->wait();
            $this->fail('Expected the rich RPC error.');
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

    public function testOneDeadlineCoversCallsAndExplicitRetries(): void
    {
        $client = $this->newTestClient();

        try {
            $client->unary(
                (new TestRequest)->setValue('delay:0.2'),
                options: ['timeout' => 0.05],
            )->wait();
            $this->fail('Expected the local deadline to terminate the call.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::DeadlineExceeded, $exception->status()->code());
        }

        $retry = new RetryPolicy(
            maxAttempts: 2,
            initialBackoff: 0.001,
            maxBackoff: 0.001,
        );
        $unary = $client->unary(
            (new TestRequest)->setValue('retry:pre'),
            options: ['timeout' => 2.0, 'retry' => $retry],
        )->wait();
        $this->assertSame('retried:1', $unary->getValue());

        $stream = $client->serverStream(
            (new TestRequest)->setValue('retry:pre'),
            options: ['timeout' => 2.0, 'retry' => $retry],
        );
        $this->assertSame('retried:1', $stream->read()?->getValue());
        $this->assertNull($stream->read());
    }

    public function testReceiveLimitRetiresTheConnectionAndTheNextCallReconnects(): void
    {
        $client = $this->newTestClient([
            'max_receive_message_size' => 64,
            'timeout' => 2.0,
        ]);

        try {
            $client->unary((new TestRequest)->setValue('response-size:1024'))->wait();
            $this->fail('Expected the oversized response to exceed the client receive limit.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::ResourceExhausted, $exception->status()->code());
        }

        $reply = $client->unary((new TestRequest)->setValue('recovered'))->wait();

        $this->assertSame('unary:recovered', $reply->getValue());
    }

    public function testServerSendLimitReturnsResourceExhausted(): void
    {
        $client = $this->newTestClient(['timeout' => 2.0]);
        $responseSize = 4 * 1024 * 1024 + 1;

        try {
            $client->unary((new TestRequest)->setValue("response-size:{$responseSize}"))->wait();
            $this->fail('Expected the response to exceed the server send limit.');
        } catch (RpcException $exception) {
            $this->assertSame(StatusCode::ResourceExhausted, $exception->status()->code());
        }
    }

    public function testMultiplexesOneHundredReorderedCallsAcrossReusableConnections(): void
    {
        $client = $this->newTestClient([
            'connections' => 2,
            'timeout' => 3.0,
        ]);
        $completionOrder = [];
        $calls = [];

        for ($index = 0; $index < 100; ++$index) {
            $calls[$index] = function () use ($client, $index, &$completionOrder): string {
                $value = $index % 10 === 0 ? 'delay:0.05' : "value-{$index}";
                $reply = $client->unary((new TestRequest)->setValue($value))->wait();
                $completionOrder[] = $reply->getValue();

                return $reply->getValue();
            };
        }

        $responses = parallel($calls);

        $this->assertCount(100, $responses);

        foreach ($responses as $index => $response) {
            $this->assertSame(
                $index % 10 === 0 ? 'delayed' : "unary:value-{$index}",
                $response,
            );
        }

        $this->assertNotSame('delayed', $completionOrder[0]);
    }
}
