<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Grpc\Fixtures;

use Generator;
use Google\Protobuf\Any;
use Google\Rpc\ErrorInfo;
use Google\Rpc\Status as RichStatus;
use Hypervel\Grpc\Exceptions\RpcException;
use Hypervel\Grpc\Server\GrpcResponse;
use Hypervel\Grpc\Server\ServerCallContext;
use Hypervel\Grpc\StatusCode;
use Hypervel\Tests\Grpc\Fixtures\TestReply;
use Hypervel\Tests\Grpc\Fixtures\TestRequest;

class TestService
{
    private const MAX_TEST_RESPONSE_SIZE = 5 * 1024 * 1024;

    /**
     * Handle the integration fixture's unary call.
     */
    public function unary(TestRequest $request, ServerCallContext $call): GrpcResponse
    {
        $value = $request->getValue();

        if ($value === 'error:not-found') {
            throw (new RpcException(
                StatusCode::NotFound,
                'The requested test value was not found.',
            ))->withTrailingMetadata(['x-error-source' => 'hypervel']);
        }

        if ($value === 'error:rich') {
            $detail = new Any;
            $detail->pack(
                (new ErrorInfo)
                    ->setReason('INVALID_TEST_VALUE')
                    ->setDomain('hypervel.dev')
                    ->setMetadata(['value' => $value]),
            );

            throw RpcException::fromStatus(
                (new RichStatus)
                    ->setCode(StatusCode::InvalidArgument->value)
                    ->setMessage('The test value is invalid.')
                    ->setDetails([$detail]),
            );
        }

        if (str_starts_with($value, 'response-size:')) {
            $responseSize = substr($value, strlen('response-size:'));

            if (preg_match('/^[0-9]+$/D', $responseSize) !== 1
                || (int) $responseSize > self::MAX_TEST_RESPONSE_SIZE) {
                throw new RpcException(StatusCode::InvalidArgument, 'The response size is invalid.');
            }

            $value = str_repeat('x', (int) $responseSize);
        } elseif (str_starts_with($value, 'delay:')) {
            $seconds = filter_var(
                substr($value, strlen('delay:')),
                FILTER_VALIDATE_FLOAT,
            );

            if ($seconds === false || $seconds < 0) {
                throw new RpcException(StatusCode::InvalidArgument, 'The delay is invalid.');
            }

            usleep((int) ceil($seconds * 1_000_000));
            $value = 'delayed';
        } elseif ($value === 'retry:pre') {
            if ($call->previousAttempts() === 0) {
                throw new RpcException(
                    StatusCode::Unavailable,
                    'Retry the uncommitted call.',
                );
            }

            $value = 'retried:' . $call->previousAttempts();
        } else {
            $value = 'unary:' . $value;
        }

        return GrpcResponse::make((new TestReply)->setValue($value))
            ->withInitialMetadata($this->initialMetadata($call))
            ->withTrailingMetadata($this->trailingMetadata($call));
    }

    /**
     * Handle the integration fixture's server-streaming call.
     */
    public function serverStream(TestRequest $request, ServerCallContext $call): GrpcResponse
    {
        return GrpcResponse::stream($this->responses($request, $call))
            ->withInitialMetadata($this->initialMetadata($call))
            ->withTrailingMetadata($this->trailingMetadata($call));
    }

    /**
     * Produce the integration fixture's server-streamed replies.
     *
     * @return Generator<int, TestReply>
     */
    private function responses(TestRequest $request, ServerCallContext $call): Generator
    {
        $value = $request->getValue();

        if ($value === 'empty') {
            return;
        }

        if ($value === 'pre-error') {
            throw new RpcException(
                StatusCode::Unavailable,
                'The stream failed before its first response.',
            );
        }

        if ($value === 'retry:pre') {
            if ($call->previousAttempts() === 0) {
                throw new RpcException(
                    StatusCode::Unavailable,
                    'Retry the uncommitted stream.',
                );
            }

            yield (new TestReply)->setValue('retried:' . $call->previousAttempts());

            return;
        }

        if ($value === 'partial-error') {
            yield (new TestReply)->setValue('stream:partial:1');

            throw (new RpcException(
                StatusCode::NotFound,
                'The stream failed after a response.',
            ))->withTrailingMetadata(['x-error-source' => 'hypervel']);
        }

        for ($index = 1; $index <= 3; ++$index) {
            yield (new TestReply)->setValue("stream:{$value}:{$index}");
        }
    }

    /**
     * Build response metadata sent before the first message.
     *
     * @return array<string, list<string>|string>
     */
    private function initialMetadata(ServerCallContext $call): array
    {
        $metadata = ['x-test-peer' => 'hypervel'];

        if ($call->metadata()->has('x-echo')) {
            $metadata['x-echo'] = $call->metadata()->values('x-echo');
        }

        return $metadata;
    }

    /**
     * Build response metadata sent with the final status.
     *
     * @return array<string, list<string>|string>
     */
    private function trailingMetadata(ServerCallContext $call): array
    {
        $metadata = ['x-test-trailer' => 'hypervel'];

        if ($call->metadata()->has('echo-bin')) {
            $metadata['echo-bin'] = $call->metadata()->values('echo-bin');
        }

        return $metadata;
    }
}
