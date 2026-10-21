<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client;

use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Primitives\Tool;
use Hypervel\Mcp\Client\Schema\ToolResult;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Support\Collection;
use Hypervel\Tests\Mcp\Fixtures\Client\FakeTransport;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use stdClass;

use function Hypervel\Tests\Mcp\Fixtures\initializeResponse;

class ToolsTest extends TestCase
{
    public function testReturnsACollectionOfToolsKeyedByName(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [
                    ['name' => 'add', 'description' => 'Adds two numbers'],
                    ['name' => 'subtract', 'description' => 'Subtracts two numbers'],
                ],
            ],
        ]);

        $tools = (new Client($transport))->tools();

        $this->assertInstanceOf(Collection::class, $tools);
        $this->assertCount(2, $tools);
        $this->assertSame(['add', 'subtract'], $tools->keys()->all());
        $this->assertInstanceOf(Tool::class, $tools['add']);
        $this->assertSame('add', $tools['add']->name);
        $this->assertSame('Adds two numbers', $tools['add']->description);
        $request = json_decode($transport->sent[2], true);
        $this->assertSame('tools/list', $request['method']);
        $this->assertArrayNotHasKey('params', $request);
    }

    public function testAutoPaginatesToolsListUntilNextCursorIsAbsent(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [['name' => 'first']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => [
                'tools' => [['name' => 'second'], ['name' => 'third']],
            ],
        ]);

        $tools = (new Client($transport))->tools();

        $this->assertSame(['first', 'second', 'third'], $tools->keys()->all());
        $this->assertArrayNotHasKey('params', json_decode($transport->sent[2], true));
        $this->assertSame('cursor-page-2', json_decode($transport->sent[3], true)['params']['cursor']);
    }

    public function testStopsPaginatingOnceLimitIsReachedWithoutFetchingTheNextPage(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);

        $tools = (new Client($transport))->tools(2);

        $this->assertSame(['a', 'b'], $tools->keys()->all());
        $this->assertCount(3, $transport->sent);
        $this->assertEmpty($transport->responses);
    }

    public function testReturnsNoToolsWithoutConnectingWhenLimitIsZero(): void
    {
        $transport = new FakeTransport;
        $client = new Client($transport);

        $tools = $client->tools(0);

        $this->assertInstanceOf(Collection::class, $tools);
        $this->assertCount(0, $tools);
        $this->assertFalse($client->connected());
        $this->assertEmpty($transport->sent);
    }

    public function testThrowsWhenTheToolLimitIsNegative(): void
    {
        $transport = new FakeTransport;

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Tool list limit must be greater than or equal to zero.');
        try {
            (new Client($transport))->tools(-1);
        } finally {
            $this->assertEmpty($transport->sent);
        }
    }

    public function testThrowsWhenToolsListDoesNotReturnAToolsArray(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => 'not-an-array',
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid tools/list response from server.');
        (new Client($transport))->tools();
    }

    public function testThrowsWhenAToolPayloadIsNotAnObject(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => ['invalid'],
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid tool payload from server.');
        (new Client($transport))->tools();
    }

    #[TestWith([[]])]
    #[TestWith([['name' => '']])]
    #[TestWith([['name' => '   ']])]
    public function testThrowsWhenAToolNameIsMissingOrEmpty(array $payload): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [$payload],
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid tool payload from server.');
        (new Client($transport))->tools();
    }

    #[TestWith([['name' => 123]])]
    #[TestWith([['title' => 1]])]
    #[TestWith([['description' => []]])]
    #[TestWith([['inputSchema' => 'object']])]
    #[TestWith([['outputSchema' => 'object']])]
    #[TestWith([['annotations' => 'none']])]
    #[TestWith([['_meta' => 'meta']])]
    public function testThrowsWhenAToolPayloadHasAFieldOfTheWrongType(array $payload): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [array_merge(['name' => 'add'], $payload)],
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid tool payload from server.');
        (new Client($transport))->tools();
    }

    public function testThrowsWhenAServerRepeatsAToolsListCursor(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [['name' => 'first']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => [
                'tools' => [['name' => 'second']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Repeated tools/list cursor [cursor-page-2] received from server.');
        try {
            (new Client($transport))->tools();
        } finally {
            $this->assertCount(4, $transport->sent);
        }
    }

    #[TestWith([123])]
    #[TestWith([[1, 2, 3]])]
    public function testThrowsWhenToolsListReturnsANonStringCursor(mixed $nextCursor): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'tools' => [['name' => 'first']],
                'nextCursor' => $nextCursor,
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid tools/list cursor from server.');
        (new Client($transport))->tools();
    }

    public function testReturnsAnUnboundToolWhoseCallThrowsUntilItIsBound(): void
    {
        $tool = Tool::from(null, ['name' => 'say-hi', 'description' => 'Greets a person']);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Tool [say-hi] is not bound to a client.');
        $tool->call();
    }

    public function testCallsABoundToolReturnedFromTools(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['tools' => [['name' => 'say-hi', 'description' => 'Greets a person']]],
        ]);
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['content' => [['type' => 'text', 'text' => 'Hello, John!']], 'isError' => false],
        ]);

        $tool = (new Client($transport))->tools()['say-hi'];

        $this->assertSame('Hello, John!', $tool->call(['name' => 'John'])->text());
        $request = json_decode($transport->sent[3], true);
        $this->assertSame('tools/call', $request['method']);
        $this->assertSame('say-hi', $request['params']['name']);
        $this->assertSame(['name' => 'John'], $request['params']['arguments']);
    }

    public function testSendsToolsCallByNameAndConcatenatesTextContent(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'content' => [
                    ['type' => 'text', 'text' => 'Hello, '],
                    ['type' => 'image', 'data' => 'base64', 'mimeType' => 'image/png'],
                    ['type' => 'text', 'text' => 'John!'],
                ],
                'isError' => false,
            ],
        ]);

        $result = (new Client($transport))->callTool('say-hi', ['name' => 'John']);

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertFalse($result->isError);
        $this->assertCount(3, $result->content);
        $this->assertSame('Hello, John!', $result->text());
        $this->assertSame('Hello, John!', (string) $result);
        $request = json_decode($transport->sent[2], true);
        $this->assertSame('tools/call', $request['method']);
        $this->assertSame('say-hi', $request['params']['name']);
        $this->assertSame(['name' => 'John'], $request['params']['arguments']);
    }

    public function testEncodesEmptyArgumentsAsAnObjectOnTheWire(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['content' => [], 'isError' => false],
        ]);

        (new Client($transport))->callTool('no-args');

        $this->assertInstanceOf(stdClass::class, json_decode($transport->sent[2])->params->arguments);
    }

    public function testPreservesStructuredContentAndMetaFromTheServerResponse(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'content' => [['type' => 'text', 'text' => '{"temp": 72}']],
                'structuredContent' => ['temp' => 72, 'conditions' => 'sunny'],
                'isError' => false,
                '_meta' => ['source' => 'cached', 'duration_ms' => 12],
            ],
        ]);

        $result = (new Client($transport))->callTool('weather', ['city' => 'NYC']);

        $this->assertSame(['temp' => 72, 'conditions' => 'sunny'], $result->structuredContent);
        $this->assertSame(['source' => 'cached', 'duration_ms' => 12], $result->meta);
    }

    public function testSurfacesToolLevelErrorsAsToolResultIsErrorTrue(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'content' => [['type' => 'text', 'text' => 'Validation failed.']],
                'isError' => true,
            ],
        ]);

        $result = (new Client($transport))->callTool('say-hi');

        $this->assertTrue($result->isError);
        $this->assertSame('Validation failed.', $result->text());
    }

    #[TestWith([['content' => 'not-an-array']])]
    #[TestWith([['content' => [], 'isError' => 'true']])]
    public function testThrowsWhenAToolsCallResultHasAFieldOfTheWrongType(array $result): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => $result,
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid tools/call result from server.');
        (new Client($transport))->callTool('say-hi');
    }
}
