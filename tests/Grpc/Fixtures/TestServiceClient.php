<?php

declare(strict_types=1);

namespace Hypervel\Tests\Grpc\Fixtures;

use Hypervel\Grpc\Client\BaseClient;
use Hypervel\Grpc\Client\BidiStreamingCall;
use Hypervel\Grpc\Client\ClientStreamingCall;
use Hypervel\Grpc\Client\ServerStreamingCall;
use Hypervel\Grpc\Client\UnaryCall;
use Hypervel\Grpc\Metadata;

class TestServiceClient extends BaseClient
{
    /**
     * Start a unary test call.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function unary(
        TestRequest $request,
        array|Metadata $metadata = [],
        array $options = [],
    ): UnaryCall {
        return $this->_simpleRequest(
            '/hypervel.grpc.testing.TestService/Unary',
            $request,
            [TestReply::class, 'decode'],
            $metadata,
            $options,
        );
    }

    /**
     * Start a server-streaming test call.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function serverStream(
        TestRequest $request,
        array|Metadata $metadata = [],
        array $options = [],
    ): ServerStreamingCall {
        return $this->_serverStreamRequest(
            '/hypervel.grpc.testing.TestService/ServerStream',
            $request,
            [TestReply::class, 'decode'],
            $metadata,
            $options,
        );
    }

    /**
     * Start a client-streaming test call.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function clientStream(
        array|Metadata $metadata = [],
        array $options = [],
    ): ClientStreamingCall {
        return $this->_clientStreamRequest(
            '/hypervel.grpc.testing.TestService/ClientStream',
            [TestReply::class, 'decode'],
            $metadata,
            $options,
        );
    }

    /**
     * Start a bidirectional-streaming test call.
     *
     * @param array<string, list<string>|string>|Metadata $metadata
     * @param array<string, mixed> $options
     */
    public function bidiStream(
        array|Metadata $metadata = [],
        array $options = [],
    ): BidiStreamingCall {
        return $this->_bidiRequest(
            '/hypervel.grpc.testing.TestService/BidiStream',
            [TestReply::class, 'decode'],
            $metadata,
            $options,
        );
    }
}
