<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Exceptions;

use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class JsonRpcExceptionTest extends TestCase
{
    public function testConvertsToJsonRpcErrorWithoutIdAndWithData(): void
    {
        $exception = new JsonRpcException(
            message: 'Invalid params',
            code: -32602,
            data: ['hint' => 'Missing field']
        );

        $response = $exception->toJsonRpcResponse();

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32602,
                'message' => 'Invalid params',
                'data' => ['hint' => 'Missing field'],
            ],
        ], $response->toArray());
    }

    public function testConvertsToJsonRpcErrorWithIdAndWithoutData(): void
    {
        $exception = new JsonRpcException(
            message: 'Not found',
            code: -32601,
            requestId: 'abc-123',
        );

        $response = $exception->toJsonRpcResponse();

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'id' => 'abc-123',
            'error' => [
                'code' => -32601,
                'message' => 'Not found',
            ],
        ], $response->toArray());
    }

    #[DataProvider('invalidIds')]
    public function testCoercesNonStringAndNonIntegerRequestIdsToNull(mixed $invalidId): void
    {
        $exception = new JsonRpcException(
            message: 'Invalid Request',
            code: -32600,
            requestId: $invalidId,
        );

        $response = $exception->toJsonRpcResponse();

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32600,
                'message' => 'Invalid Request',
            ],
        ], $response->toArray());
    }

    /**
     * Provide invalid request identifiers.
     */
    public static function invalidIds(): array
    {
        return [
            'float' => [1.0],
            'array' => [['foo' => 'bar']],
            'bool' => [true],
            'object' => [(object) ['foo' => 'bar']],
        ];
    }
}
