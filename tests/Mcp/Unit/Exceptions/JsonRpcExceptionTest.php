<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Exceptions;

use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class JsonRpcExceptionTest extends TestCase
{
    #[DataProvider('errorData')]
    public function testConvertsToJsonRpcErrorWithoutIdAndWithData(mixed $data): void
    {
        $exception = new JsonRpcException(
            message: 'Invalid params',
            code: -32602,
            data: $data
        );

        $response = $exception->toJsonRpcResponse();

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'error' => [
                'code' => -32602,
                'message' => 'Invalid params',
                'data' => $data,
            ],
        ], $response->toArray());
        $this->assertSame($data, $exception->data());
        $this->assertSame($data, json_decode($response->toJson(), true, flags: JSON_THROW_ON_ERROR)['error']['data']);
    }

    /**
     * Provide structured and primitive JSON error details.
     */
    public static function errorData(): array
    {
        return [[['hint' => 'Missing field']], ['Missing field'], [0], [false]];
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
