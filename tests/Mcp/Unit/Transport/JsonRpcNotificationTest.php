<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Transport;

use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Transport\JsonRpcNotification;
use Hypervel\Tests\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;

class JsonRpcNotificationTest extends TestCase
{
    public function testCanCreateANotificationMessageFromValidJson(): void
    {
        $request = JsonRpcNotification::from([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);

        $this->assertInstanceOf(JsonRpcNotification::class, $request);
        $this->assertSame('notifications/initialized', $request->method);
        $this->assertSame([], $request->params);
    }

    public function testThrowsExceptionForMissingJsonrpcVersionInNotification(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid Request: Invalid JSON-RPC version. Must be "2.0".');
        $this->expectExceptionCode(-32600);

        JsonRpcNotification::from([
            'method' => 'notifications/ping',
        ]);
    }

    public function testThrowsExceptionForIncorrectJsonrpcVersionInNotification(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid Request: Invalid JSON-RPC version. Must be "2.0".');
        $this->expectExceptionCode(-32600);

        JsonRpcNotification::from([
            'jsonrpc' => '1.0',
            'method' => 'notifications/ping',
        ]);
    }

    public function testThrowsExceptionForMissingMethodInNotification(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid Request: Invalid or missing "method". Must be a string.');
        $this->expectExceptionCode(-32600);

        JsonRpcNotification::from([
            'jsonrpc' => '2.0',
        ]);
    }

    public function testThrowsExceptionForNonStringMethodInNotification(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid Request: Invalid or missing "method". Must be a string.');
        $this->expectExceptionCode(-32600);

        JsonRpcNotification::from([
            'jsonrpc' => '2.0',
            'method' => 123,
        ]);
    }

    #[DataProvider('invalidParams')]
    public function testThrowsExceptionForInvalidParamsTypeInNotification(mixed $params): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid params: The [params] member must be an object.');
        $this->expectExceptionCode(-32602);

        JsonRpcNotification::from([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => $params,
        ]);
    }

    /**
     * Provide invalid notification parameters.
     */
    public static function invalidParams(): array
    {
        return [
            'string' => ['invalid'],
            'integer' => [1],
            'boolean' => [true],
            'null' => [null],
            'list' => [[1, 2, 3]],
        ];
    }

    public function testSerializesToAnArrayWithParamsWhenPresent(): void
    {
        $notification = new JsonRpcNotification('notifications/progress', ['progress' => 50]);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progress' => 50],
        ], $notification->toArray());
    }

    public function testOmitsEmptyParamsWhenSerializing(): void
    {
        $notification = new JsonRpcNotification('notifications/initialized', []);

        $this->assertEquals([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ], $notification->toArray());
    }

    public function testSerializesToJsonWithoutAnId(): void
    {
        $notification = new JsonRpcNotification('notifications/initialized', []);

        $this->assertSame('{"jsonrpc":"2.0","method":"notifications\/initialized"}', $notification->toJson());
    }

    public function testRejectsInvalidArgumentBytesInsteadOfChangingThem(): void
    {
        $this->expectException(JsonException::class);

        (new JsonRpcNotification('notifications/progress', ['message' => "\xFF"]))->toJson();
    }
}
