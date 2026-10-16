<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Transport;

use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Transport\JsonRpcRequest;
use Hypervel\Tests\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;

class JsonRpcRequestTest extends TestCase
{
    public function testCanCreateAMessageFromValidJson(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo',
                'arguments' => [
                    'message' => 'Hello, world!',
                ],
            ],
        ]);

        $this->assertInstanceOf(JsonRpcRequest::class, $request);
        $this->assertSame(1, $request->id);
        $this->assertSame('tools/call', $request->method);
        $this->assertSame(['name' => 'echo', 'arguments' => ['message' => 'Hello, world!']], $request->params);
    }

    public function testThrowsExceptionForMissingJsonrpcVersion(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid Request: The [jsonrpc] member must be exactly [2.0].');
        $this->expectExceptionCode(-32600);

        JsonRpcRequest::from([
            'id' => 1,
            'method' => 'initialize',
        ]);
    }

    public function testThrowsExceptionForIncorrectJsonrpcVersion(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid Request: The [jsonrpc] member must be exactly [2.0].');
        $this->expectExceptionCode(-32600);

        JsonRpcRequest::from([
            'jsonrpc' => '1.0',
            'id' => 1,
            'method' => 'initialize',
        ]);
    }

    public function testThrowsExceptionForInvalidIdType(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid Request: The [id] member must be a string, number.');
        $this->expectExceptionCode(-32600);

        JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1.5,
            'method' => 'initialize',
        ]);
    }

    public function testThrowsExceptionForMissingMethod(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid Request: The [method] member is required and must be a string.');
        $this->expectExceptionCode(-32600);

        JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
        ]);
    }

    public function testThrowsExceptionForNonStringMethod(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid Request: The [method] member is required and must be a string.');
        $this->expectExceptionCode(-32600);

        JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 123,
        ]);
    }

    #[DataProvider('invalidObjects')]
    public function testThrowsExceptionForInvalidParamsType(mixed $params): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid params: The [params] member must be an object.');
        $this->expectExceptionCode(-32602);

        JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => $params,
        ]);
    }

    /**
     * Provide values that cannot represent a parameter object.
     */
    public static function invalidObjects(): array
    {
        return [
            'string' => ['invalid'],
            'integer' => [1],
            'boolean' => [true],
            'null' => [null],
            'list' => [[1, 2, 3]],
        ];
    }

    public function testDefaultsParamsToEmptyArrayAndSupportsGetters(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 'abc',
            'method' => 'something/do',
            // no params
        ]);

        $this->assertSame([], $request->params);
        $this->assertSame('default', $request->get('missing', 'default'));
        $this->assertNull($request->cursor());

        $requestWithCursor = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'other',
            'params' => ['cursor' => 'CUR123', 'foo' => 'bar'],
        ]);

        $this->assertSame('CUR123', $requestWithCursor->cursor());
        $this->assertSame('bar', $requestWithCursor->get('foo'));
    }

    public function testExtractsMetaFromParams(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo',
                '_meta' => [
                    'progressToken' => 'token-123',
                    'customKey' => 'customValue',
                ],
            ],
        ]);

        $this->assertSame([
            'progressToken' => 'token-123',
            'customKey' => 'customValue',
        ], $request->meta());
        $this->assertArrayHasKey('_meta', $request->params);
        $this->assertSame('echo', $request->params['name']);
    }

    public function testHasNullMetaWhenNotProvided(): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo',
            ],
        ]);

        $this->assertNull($request->meta());
    }

    public function testPassesMetaToRequestObject(): void
    {
        $jsonRpcRequest = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'arguments' => ['message' => 'Hello'],
                '_meta' => ['requestId' => '456'],
            ],
        ]);

        $request = $jsonRpcRequest->toRequest();

        $this->assertSame(['requestId' => '456'], $request->meta());
    }

    #[DataProvider('invalidObjects')]
    public function testThrowsExceptionForInvalidArgumentsType(mixed $arguments): void
    {
        $request = JsonRpcRequest::from([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => [
                'name' => 'echo',
                'arguments' => $arguments,
            ],
        ]);

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Invalid params: The [arguments] member must be an object.');
        $this->expectExceptionCode(-32602);
        $request->toRequest();
    }

    public function testSerializesToAnArrayWithParamsWhenPresent(): void
    {
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'echo']);

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/call',
            'params' => ['name' => 'echo'],
        ], $request->toArray());
    }

    public function testOmitsEmptyParamsWhenSerializing(): void
    {
        $request = new JsonRpcRequest(2, 'ping', []);

        $this->assertSame([
            'jsonrpc' => '2.0',
            'id' => 2,
            'method' => 'ping',
        ], $request->toArray());
    }

    public function testSerializesToJson(): void
    {
        $request = new JsonRpcRequest(3, 'ping', []);

        $this->assertSame('{"jsonrpc":"2.0","id":3,"method":"ping"}', $request->toJson());
    }

    public function testMirrorsTheMethodIntoAHeader(): void
    {
        $request = new JsonRpcRequest(1, 'tools/list', []);

        $this->assertSame(['Mcp-Method' => 'tools/list'], $request->mirroredHeaders());
        $this->assertFalse($request->requiresName());
        $this->assertNull($request->name());
    }

    #[DataProvider('namedTargets')]
    public function testMirrorsTheNamedTargetIntoAHeader(string $method, string $key, string $value): void
    {
        $request = new JsonRpcRequest(1, $method, [$key => $value]);

        $this->assertSame([
            'Mcp-Method' => $method,
            'Mcp-Name' => $value,
        ], $request->mirroredHeaders());
        $this->assertTrue($request->requiresName());
        $this->assertSame($value, $request->name());
    }

    /**
     * Provide methods with a named target.
     */
    public static function namedTargets(): array
    {
        return [
            ['tools/call', 'name', 'get_weather'],
            ['prompts/get', 'name', 'summarize'],
            ['resources/read', 'uri', 'file:///projects/myapp/config.json'],
        ];
    }

    public function testEncodesAMirroredNameThatIsNotHeaderSafe(): void
    {
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 'Hello, 世界']);

        $this->assertSame('=?base64?SGVsbG8sIOS4lueVjA==?=', $request->mirroredHeaders()['Mcp-Name']);
    }

    public function testOmitsTheNameHeaderWhenTheBodyCarriesNoUsableName(): void
    {
        $request = new JsonRpcRequest(1, 'tools/call', ['name' => 123]);

        $this->assertSame(['Mcp-Method' => 'tools/call'], $request->mirroredHeaders());
        $this->assertTrue($request->requiresName());
        $this->assertNull($request->name());
    }

    public function testPreservesNumericObjectPropertiesFromTheWire(): void
    {
        $source = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"arguments":{"0":"first","1":"second"}}}';
        $request = JsonRpcRequest::from(json_decode($source, true, flags: JSON_THROW_ON_ERROR));
        $request->source = $source;

        $this->assertSame(['first', 'second'], $request->toRequest()->all());
    }

    public function testAcceptsAnAlreadyDecodedObjectWithNumericProperties(): void
    {
        $request = new JsonRpcRequest(1, 'tools/call', ['arguments' => (object) ['first', 'second']]);

        $this->assertSame(['first', 'second'], $request->toRequest()->all());
    }

    public function testRejectsAWireList(): void
    {
        $source = '{"jsonrpc":"2.0","id":1,"method":"tools/call","params":{"arguments":["first","second"]}}';
        $request = JsonRpcRequest::from(json_decode($source, true, flags: JSON_THROW_ON_ERROR));
        $request->source = $source;

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(-32602);
        $request->toRequest();
    }

    public function testRejectsAnInvalidCursorAsInvalidParams(): void
    {
        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(-32602);

        (new JsonRpcRequest(1, 'tools/list', ['cursor' => ['invalid']]))->cursor();
    }

    public function testRejectsInvalidArgumentBytesInsteadOfChangingThem(): void
    {
        $this->expectException(JsonException::class);

        (new JsonRpcRequest(1, 'tools/call', ['arguments' => ['message' => "\xFF"]]))->toJson();
    }
}
