<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Grpc;

use Hypervel\Contracts\Engine\Http\V2\ResponseInterface;
use Hypervel\Engine\Http\V2\ClientFactory;
use Hypervel\Engine\Http\V2\Request;
use Hypervel\Grpc\StatusCode;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class PreflightTest extends GrpcIntegrationTestCase
{
    public function testRejectsHttpOneBeforeGrpcMediaTypeHandling(): void
    {
        $body = pack('CN', 0, 0);
        $request = implode("\r\n", [
            'POST /hypervel.grpc.testing.TestService/Unary HTTP/1.1',
            'Host: ' . $this->target(),
            'Content-Type: application/grpc+proto',
            'TE: trailers',
            'Content-Length: ' . strlen($body),
            'Connection: close',
            '',
            $body,
        ]);
        $socket = stream_socket_client(
            'tcp://' . $this->target(),
            $errorCode,
            $errorMessage,
            5.0,
        );

        if ($socket === false) {
            throw new RuntimeException(
                "Unable to connect to the gRPC test server: {$errorMessage}",
                $errorCode,
            );
        }

        try {
            stream_set_timeout($socket, 5);
            $this->assertSame(strlen($request), fwrite($socket, $request));
            $response = stream_get_contents($socket);
        } finally {
            fclose($socket);
        }

        $this->assertStringContainsString(' 505 ', $response);
        $this->assertStringNotContainsString('application/grpc', strtolower($response));
    }

    /**
     * @param array<string, string> $headers
     */
    #[DataProvider('rawHttpTwoFailures')]
    public function testRejectsInvalidRawHttpTwoRequests(
        string $method,
        string $path,
        array $headers,
        string $body,
        int $httpStatus,
        ?StatusCode $grpcStatus,
    ): void {
        $response = $this->sendRawHttpTwoRequest($method, $path, $headers, $body);

        $this->assertSame($httpStatus, $response['status']);

        if ($grpcStatus === null) {
            $contentType = $response['headers']['content-type'] ?? null;
            $this->assertFalse(
                is_string($contentType)
                && str_starts_with(strtolower($contentType), 'application/grpc'),
            );
            $this->assertArrayNotHasKey('grpc-status', $response['headers']);
        } else {
            $this->assertSame(
                'application/grpc+proto',
                $response['headers']['content-type'],
            );
            $this->assertSame(
                (string) $grpcStatus->value,
                $response['headers']['grpc-status'],
            );
        }

        if ($httpStatus === 405) {
            $this->assertSame('POST', $response['headers']['allow']);
        }

        if (($headers['grpc-encoding'] ?? null) === 'br') {
            $this->assertSame(
                'identity,gzip',
                $response['headers']['grpc-accept-encoding'],
            );
        }
    }

    /**
     * Return malformed requests for the real HTTP/2 listener.
     *
     * @return iterable<string, array{string, string, array<string, string>, string, int, null|StatusCode}>
     */
    public static function rawHttpTwoFailures(): iterable
    {
        $path = '/hypervel.grpc.testing.TestService/Unary';
        $frame = pack('CN', 0, 0);
        $headers = [
            'content-type' => 'application/grpc+proto',
            'te' => 'trailers',
        ];

        yield 'non-post method' => ['GET', $path, $headers, $frame, 405, null];
        yield 'missing content type' => [
            'POST',
            $path,
            ['te' => 'trailers'],
            $frame,
            415,
            null,
        ];
        yield 'non-gRPC content type' => [
            'POST',
            $path,
            ['content-type' => 'application/json', 'te' => 'trailers'],
            $frame,
            415,
            null,
        ];
        yield 'unsupported gRPC JSON subtype' => [
            'POST',
            $path,
            ['content-type' => 'application/grpc+json', 'te' => 'trailers'],
            $frame,
            415,
            null,
        ];
        yield 'unsupported custom gRPC subtype' => [
            'POST',
            $path,
            ['content-type' => 'application/grpc+custom', 'te' => 'trailers'],
            $frame,
            415,
            null,
        ];
        yield 'missing TE' => [
            'POST',
            $path,
            ['content-type' => 'application/grpc+proto'],
            $frame,
            200,
            StatusCode::Internal,
        ];
        yield 'invalid TE' => [
            'POST',
            $path,
            ['content-type' => 'application/grpc+proto', 'te' => 'gzip'],
            $frame,
            200,
            StatusCode::Internal,
        ];
        yield 'unknown canonical method' => [
            'POST',
            '/hypervel.grpc.testing.TestService/Missing',
            $headers,
            $frame,
            200,
            StatusCode::Unimplemented,
        ];
        yield 'query string' => [
            'POST',
            $path . '?value=test',
            $headers,
            $frame,
            200,
            StatusCode::Unimplemented,
        ];
        yield 'missing leading slash' => [
            'POST',
            ltrim($path, '/'),
            $headers,
            $frame,
            200,
            StatusCode::Unimplemented,
        ];
        yield 'doubled leading slash' => [
            'POST',
            '/' . $path,
            $headers,
            $frame,
            200,
            StatusCode::Unimplemented,
        ];
        yield 'trailing slash' => [
            'POST',
            $path . '/',
            $headers,
            $frame,
            200,
            StatusCode::Unimplemented,
        ];
        yield 'extra path segment' => [
            'POST',
            $path . '/Extra',
            $headers,
            $frame,
            200,
            StatusCode::Unimplemented,
        ];
        yield 'invalid method identifier' => [
            'POST',
            '/hypervel.grpc.testing.TestService/invalid-method',
            $headers,
            $frame,
            200,
            StatusCode::Unimplemented,
        ];
        yield 'malformed timeout' => [
            'POST',
            $path,
            [...$headers, 'grpc-timeout' => '1second'],
            $frame,
            200,
            StatusCode::Internal,
        ];
        yield 'unsupported compression' => [
            'POST',
            $path,
            [...$headers, 'grpc-encoding' => 'br'],
            $frame,
            200,
            StatusCode::Unimplemented,
        ];
        yield 'oversized metadata' => [
            'POST',
            $path,
            [...$headers, 'x-padding' => str_repeat('a', 9_000)],
            $frame,
            200,
            StatusCode::ResourceExhausted,
        ];
        yield 'invalid frame flag' => [
            'POST',
            $path,
            $headers,
            pack('CN', 2, 0),
            200,
            StatusCode::Internal,
        ];
        yield 'declared frame exceeds receive limit' => [
            'POST',
            $path,
            $headers,
            pack('CN', 0, 4 * 1024 * 1024 + 1),
            200,
            StatusCode::ResourceExhausted,
        ];
        yield 'truncated frame' => [
            'POST',
            $path,
            $headers,
            pack('CN', 0, 4) . 'a',
            200,
            StatusCode::Internal,
        ];
        yield 'multiple unary frames' => [
            'POST',
            $path,
            $headers,
            $frame . $frame,
            200,
            StatusCode::Internal,
        ];
        yield 'malformed protobuf' => [
            'POST',
            $path,
            $headers,
            pack('CN', 0, 3) . "\x0a\x05x",
            200,
            StatusCode::Internal,
        ];
    }

    /**
     * Send one raw request through Hypervel's engine HTTP/2 abstraction.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, list<string>|string>, body: string}
     */
    private function sendRawHttpTwoRequest(
        string $method,
        string $path,
        array $headers,
        string $body,
    ): array {
        $client = (new ClientFactory)->make(
            $this->getServerHost(),
            $this->getServerPort(),
        );

        try {
            $streamId = $client->send(new Request(
                path: $path,
                method: $method,
                body: $body,
                headers: ['host' => $this->target(), ...$headers],
                usePipelineRead: true,
            ));
            $status = 0;
            $responseHeaders = [];
            $responseBody = '';

            do {
                $response = $client->recv(5.0);

                if (! $response instanceof ResponseInterface) {
                    throw new RuntimeException('Timed out waiting for the raw gRPC response.');
                }

                $this->assertSame($streamId, $response->getStreamId());

                if ($response->getStatusCode() !== 0) {
                    $status = $response->getStatusCode();
                }

                $responseHeaders = array_replace(
                    $responseHeaders,
                    $response->getHeaders(),
                );
                $responseBody .= $response->getBody() ?? '';
            } while (! $response->isEndStream());

            return [
                'status' => $status,
                'headers' => $responseHeaders,
                'body' => $responseBody,
            ];
        } finally {
            $client->close();
        }
    }
}
