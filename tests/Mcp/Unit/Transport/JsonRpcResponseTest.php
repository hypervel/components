<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Transport;

use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Tests\TestCase;
use JsonException;

class JsonRpcResponseTest extends TestCase
{
    public function testCanReturnResponseAsArray(): void
    {
        $response = JsonRpcResponse::result(1, ['foo' => 'bar']);

        $expectedArray = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['foo' => 'bar'],
        ];

        $this->assertSame($expectedArray, $response->toArray());
    }

    public function testCanReturnResponseAsJson(): void
    {
        $response = JsonRpcResponse::result(1, ['foo' => 'bar']);

        $expectedJson = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => ['foo' => 'bar'],
        ]);

        $this->assertSame($expectedJson, $response->toJson());
    }

    public function testConvertsEmptyArrayResultToObject(): void
    {
        $response = JsonRpcResponse::result(1, []);

        $expectedJson = json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => (object) [],
        ]);

        $this->assertSame($expectedJson, $response->toJson());
    }

    public function testIncludesMetaInResultWhenProvidedInResultArray(): void
    {
        $response = JsonRpcResponse::result(
            1,
            [
                'content' => 'Hello',
                '_meta' => [
                    'requestId' => '123',
                    'timestamp' => 1234567890,
                ],
            ]
        );

        $expectedArray = [
            'jsonrpc' => '2.0',
            'id' => 1,
            'result' => [
                'content' => 'Hello',
                '_meta' => [
                    'requestId' => '123',
                    'timestamp' => 1234567890,
                ],
            ],
        ];

        $this->assertSame($expectedArray, $response->toArray());
    }

    public function testDoesNotIncludeMetaWhenNotInResult(): void
    {
        $response = JsonRpcResponse::result(1, ['content' => 'Hello']);

        $this->assertArrayNotHasKey('_meta', $response->toArray()['result']);
    }

    public function testCanCreateANotificationWithParams(): void
    {
        $response = JsonRpcResponse::notification('notifications/progress', ['progress' => 50]);

        $expectedArray = [
            'jsonrpc' => '2.0',
            'method' => 'notifications/progress',
            'params' => ['progress' => 50],
        ];

        $this->assertSame($expectedArray, $response->toArray());
    }

    public function testDoesNotEscapeUnicodeCharactersInJsonOutput(): void
    {
        $unicodeString = "\u{1F600} caf\u{00E9} \u{4F60}\u{597D}";

        $response = JsonRpcResponse::result(1, ['text' => $unicodeString]);

        $json = $response->toJson();

        $this->assertStringContainsString($unicodeString, $json);
        $this->assertDoesNotMatchRegularExpression('/\\\u[0-9a-fA-F]{4}/', $json);
    }

    public function testConvertsEmptyArrayParamsInNotificationToObject(): void
    {
        $response = JsonRpcResponse::notification('notifications/initialized', []);

        $expectedJson = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
            'params' => (object) [],
        ]);

        $this->assertSame($expectedJson, $response->toJson());
    }

    public function testSubstitutesInvalidUtf8InApplicationText(): void
    {
        $response = JsonRpcResponse::result(1, ['text' => "hello\xFF"]);

        $this->assertSame("hello\u{FFFD}", json_decode($response->toJson(), true, flags: JSON_THROW_ON_ERROR)['result']['text']);
    }

    public function testThrowsForValuesThatCannotBeEncoded(): void
    {
        $this->expectException(JsonException::class);

        JsonRpcResponse::result(1, ['value' => INF])->toJson();
    }
}
