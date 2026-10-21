<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client;

use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Primitives\Prompt;
use Hypervel\Mcp\Client\Schema\PromptResult;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Support\Collection;
use Hypervel\Tests\Mcp\Fixtures\Client\FakeTransport;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use stdClass;

use function Hypervel\Tests\Mcp\Fixtures\initializeResponse;

class PromptsTest extends TestCase
{
    public function testReturnsACollectionOfPromptsKeyedByName(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'prompts' => [
                    ['name' => 'summarize', 'description' => 'Summarizes text'],
                    ['name' => 'translate', 'description' => 'Translates text'],
                ],
            ],
        ]);

        $prompts = (new Client($transport))->prompts();

        $this->assertInstanceOf(Collection::class, $prompts);
        $this->assertCount(2, $prompts);
        $this->assertSame(['summarize', 'translate'], $prompts->keys()->all());
        $this->assertInstanceOf(Prompt::class, $prompts['summarize']);
        $this->assertSame('summarize', $prompts['summarize']->name);
        $this->assertSame('Summarizes text', $prompts['summarize']->description);
        $request = json_decode($transport->sent[2], true);
        $this->assertSame('prompts/list', $request['method']);
        $this->assertArrayNotHasKey('params', $request);
    }

    public function testAutoPaginatesPromptsListUntilNextCursorIsAbsent(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'prompts' => [['name' => 'first']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => [
                'prompts' => [['name' => 'second'], ['name' => 'third']],
            ],
        ]);

        $prompts = (new Client($transport))->prompts();

        $this->assertSame(['first', 'second', 'third'], $prompts->keys()->all());
        $this->assertArrayNotHasKey('params', json_decode($transport->sent[2], true));
        $this->assertSame('cursor-page-2', json_decode($transport->sent[3], true)['params']['cursor']);
    }

    public function testStopsPaginatingOnceThePromptLimitIsReachedWithoutFetchingTheNextPage(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'prompts' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);

        $prompts = (new Client($transport))->prompts(2);

        $this->assertSame(['a', 'b'], $prompts->keys()->all());
        $this->assertCount(3, $transport->sent);
        $this->assertEmpty($transport->responses);
    }

    public function testReturnsNoPromptsWithoutConnectingWhenLimitIsZero(): void
    {
        $transport = new FakeTransport;
        $client = new Client($transport);

        $prompts = $client->prompts(0);

        $this->assertInstanceOf(Collection::class, $prompts);
        $this->assertCount(0, $prompts);
        $this->assertFalse($client->connected());
        $this->assertEmpty($transport->sent);
    }

    public function testThrowsWhenThePromptLimitIsNegative(): void
    {
        $transport = new FakeTransport;

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Prompt list limit must be greater than or equal to zero.');
        try {
            (new Client($transport))->prompts(-1);
        } finally {
            $this->assertEmpty($transport->sent);
        }
    }

    public function testThrowsWhenPromptsListDoesNotReturnAPromptsArray(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'prompts' => 'not-an-array',
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid prompts/list response from server.');
        (new Client($transport))->prompts();
    }

    public function testThrowsWhenAPromptPayloadIsNotAnObject(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'prompts' => ['invalid'],
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid prompt payload from server.');
        (new Client($transport))->prompts();
    }

    #[TestWith([[]])]
    #[TestWith([['name' => '']])]
    #[TestWith([['name' => '   ']])]
    public function testThrowsWhenAPromptNameIsMissingOrEmpty(array $payload): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'prompts' => [$payload],
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid prompt payload from server.');
        (new Client($transport))->prompts();
    }

    #[TestWith([['name' => 123]])]
    #[TestWith([['title' => 1]])]
    #[TestWith([['description' => []]])]
    #[TestWith([['arguments' => 'none']])]
    #[TestWith([['_meta' => 'meta']])]
    public function testThrowsWhenAPromptPayloadHasAFieldOfTheWrongType(array $payload): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'prompts' => [array_merge(['name' => 'summarize'], $payload)],
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid prompt payload from server.');
        (new Client($transport))->prompts();
    }

    public function testThrowsWhenAServerRepeatsAPromptsListCursor(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'prompts' => [['name' => 'first']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => [
                'prompts' => [['name' => 'second']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Repeated prompts/list cursor [cursor-page-2] received from server.');
        try {
            (new Client($transport))->prompts();
        } finally {
            $this->assertCount(4, $transport->sent);
        }
    }

    #[TestWith([123])]
    #[TestWith([[1, 2, 3]])]
    public function testThrowsWhenPromptsListReturnsANonStringCursor(mixed $nextCursor): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'prompts' => [['name' => 'first']],
                'nextCursor' => $nextCursor,
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid prompts/list cursor from server.');
        (new Client($transport))->prompts();
    }

    public function testSendsPromptsGetByNameAndConcatenatesTextContent(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'description' => 'A greeting prompt',
                'messages' => [
                    ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hello, ']],
                    ['role' => 'assistant', 'content' => ['type' => 'image', 'data' => 'base64', 'mimeType' => 'image/png']],
                    ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'John!']],
                ],
            ],
        ]);

        $result = (new Client($transport))->getPrompt('greeting', ['name' => 'John']);

        $this->assertInstanceOf(PromptResult::class, $result);
        $this->assertSame('A greeting prompt', $result->description);
        $this->assertCount(3, $result->messages);
        $this->assertSame('Hello, John!', $result->text());
        $this->assertSame('Hello, John!', (string) $result);
        $request = json_decode($transport->sent[2], true);
        $this->assertSame('prompts/get', $request['method']);
        $this->assertSame('greeting', $request['params']['name']);
        $this->assertSame(['name' => 'John'], $request['params']['arguments']);
    }

    public function testEncodesEmptyPromptArgumentsAsAnObjectOnTheWire(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['messages' => []],
        ]);

        (new Client($transport))->getPrompt('no-args');

        $this->assertInstanceOf(stdClass::class, json_decode($transport->sent[2])->params->arguments);
    }

    public function testSkipsMessagesWhoseContentIsNotAnObjectWhenExtractingText(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'messages' => [
                    ['role' => 'user', 'content' => 'plain-string'],
                    ['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hello!']],
                ],
            ],
        ]);

        $result = (new Client($transport))->getPrompt('greeting');

        $this->assertSame('Hello!', $result->text());
    }

    public function testPreservesMetaFromThePromptsGetResponse(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'messages' => [['role' => 'user', 'content' => ['type' => 'text', 'text' => 'Hi']]],
                '_meta' => ['source' => 'cached', 'duration_ms' => 12],
            ],
        ]);

        $result = (new Client($transport))->getPrompt('greeting');

        $this->assertSame(['source' => 'cached', 'duration_ms' => 12], $result->meta);
    }

    #[TestWith([['messages' => 'not-an-array']])]
    #[TestWith([['messages' => [], 'description' => []]])]
    public function testThrowsWhenAPromptsGetResultHasAFieldOfTheWrongType(array $result): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => $result,
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid prompts/get result from server.');
        (new Client($transport))->getPrompt('greeting');
    }
}
