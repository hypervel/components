<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client;

use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Exceptions\AuthorizationRequiredException;
use Hypervel\Mcp\Client\Primitives\Resource;
use Hypervel\Mcp\Client\Schema\ResourceReadResult;
use Hypervel\Mcp\Client\Transport\HttpTransport;
use Hypervel\Mcp\Exceptions\ClientException;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Mcp\Fixtures\Client\FakeTransport;
use Hypervel\Tests\Mcp\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

use function Hypervel\Tests\Mcp\Fixtures\initializeResponse;

class ResourcesTest extends TestCase
{
    public function testReturnsACollectionOfResourcesKeyedByUri(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => [
                    ['uri' => 'file://readme', 'name' => 'readme', 'description' => 'The readme', 'mimeType' => 'text/plain', 'size' => 1024],
                    ['uri' => 'file://license', 'name' => 'license'],
                ],
            ],
        ]);

        $resources = (new Client($transport))->resources();

        $this->assertInstanceOf(Collection::class, $resources);
        $this->assertCount(2, $resources);
        $this->assertSame(['file://readme', 'file://license'], $resources->keys()->all());
        $this->assertInstanceOf(Resource::class, $resources['file://readme']);
        $this->assertSame('file://readme', $resources['file://readme']->uri);
        $this->assertSame('readme', $resources['file://readme']->name);
        $this->assertSame('The readme', $resources['file://readme']->description);
        $this->assertSame('text/plain', $resources['file://readme']->mimeType);
        $this->assertSame(1024, $resources['file://readme']->size);
        $this->assertNull($resources['file://license']->title);
        $this->assertNull($resources['file://license']->description);
        $this->assertNull($resources['file://license']->mimeType);
        $this->assertNull($resources['file://license']->size);
        $request = json_decode($transport->sent[2], true);
        $this->assertSame('resources/list', $request['method']);
        $this->assertArrayNotHasKey('params', $request);
    }

    public function testAutoPaginatesResourcesListUntilNextCursorIsAbsent(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => [['uri' => 'file://first', 'name' => 'first']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => [
                'resources' => [
                    ['uri' => 'file://second', 'name' => 'second'],
                    ['uri' => 'file://third', 'name' => 'third'],
                ],
            ],
        ]);

        $resources = (new Client($transport))->resources();

        $this->assertSame(['file://first', 'file://second', 'file://third'], $resources->keys()->all());
        $this->assertArrayNotHasKey('params', json_decode($transport->sent[2], true));
        $this->assertSame('cursor-page-2', json_decode($transport->sent[3], true)['params']['cursor']);
    }

    #[TestWith([2, ['file://a', 'file://b']])]
    #[TestWith([3, ['file://a', 'file://b', 'file://c']])]
    public function testStopsPaginatingOnceTheResourceLimitIsReachedWithoutFetchingTheNextPage(int $limit, array $expected): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => [
                    ['uri' => 'file://a', 'name' => 'a'],
                    ['uri' => 'file://b', 'name' => 'b'],
                    ['uri' => 'file://c', 'name' => 'c'],
                ],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);

        $resources = (new Client($transport))->resources($limit);

        $this->assertSame($expected, $resources->keys()->all());
        $this->assertCount(3, $transport->sent);
        $this->assertEmpty($transport->responses);
    }

    public function testReturnsNoResourcesWithoutConnectingWhenLimitIsZero(): void
    {
        $transport = new FakeTransport;
        $client = new Client($transport);

        $resources = $client->resources(0);

        $this->assertInstanceOf(Collection::class, $resources);
        $this->assertCount(0, $resources);
        $this->assertFalse($client->connected());
        $this->assertEmpty($transport->sent);
    }

    public function testThrowsWhenTheResourceLimitIsNegative(): void
    {
        $transport = new FakeTransport;

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Resource list limit must be greater than or equal to zero.');
        try {
            (new Client($transport))->resources(-1);
        } finally {
            $this->assertEmpty($transport->sent);
        }
    }

    public function testThrowsWhenResourcesListDoesNotReturnAResourcesArray(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => 'not-an-array',
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid resources/list response from server.');
        (new Client($transport))->resources();
    }

    public function testThrowsWhenAResourcePayloadIsNotAnObject(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => ['invalid'],
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid resource payload from server.');
        (new Client($transport))->resources();
    }

    #[TestWith([['name' => 'readme']])]
    #[TestWith([['uri' => '', 'name' => 'readme']])]
    #[TestWith([['uri' => '   ', 'name' => 'readme']])]
    #[TestWith([['uri' => 'file://readme']])]
    #[TestWith([['uri' => 'file://readme', 'name' => '']])]
    #[TestWith([['uri' => 'file://readme', 'name' => '   ']])]
    public function testThrowsWhenAResourcePayloadIsMissingAUriOrName(array $payload): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => [$payload],
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid resource payload from server.');
        (new Client($transport))->resources();
    }

    #[TestWith([['title' => 1]])]
    #[TestWith([['description' => []]])]
    #[TestWith([['mimeType' => 1]])]
    #[TestWith([['size' => 'big']])]
    #[TestWith([['annotations' => 'none']])]
    #[TestWith([['_meta' => 'meta']])]
    public function testThrowsWhenAResourcePayloadHasAFieldOfTheWrongType(array $payload): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => [array_merge(['uri' => 'file://readme', 'name' => 'readme'], $payload)],
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid resource payload from server.');
        (new Client($transport))->resources();
    }

    public function testPreservesTitleAnnotationsAndMetaOnAResource(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => [[
                    'uri' => 'file://readme',
                    'name' => 'readme',
                    'title' => 'Readme',
                    'annotations' => ['audience' => ['user']],
                    '_meta' => ['source' => 'cached'],
                ]],
            ],
        ]);

        $resource = (new Client($transport))->resources()['file://readme'];

        $this->assertSame('Readme', $resource->title);
        $this->assertSame(['audience' => ['user']], $resource->annotations);
        $this->assertSame(['source' => 'cached'], $resource->meta);
    }

    public function testThrowsWhenAServerRepeatsAResourcesListCursor(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => [['uri' => 'file://first', 'name' => 'first']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => [
                'resources' => [['uri' => 'file://second', 'name' => 'second']],
                'nextCursor' => 'cursor-page-2',
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Repeated resources/list cursor [cursor-page-2] received from server.');
        try {
            (new Client($transport))->resources();
        } finally {
            $this->assertCount(4, $transport->sent);
        }
    }

    #[TestWith([123])]
    #[TestWith([[1, 2, 3]])]
    public function testThrowsWhenResourcesListReturnsANonStringCursor(mixed $nextCursor): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'resources' => [['uri' => 'file://first', 'name' => 'first']],
                'nextCursor' => $nextCursor,
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid resources/list cursor from server.');
        (new Client($transport))->resources();
    }

    public function testReturnsTheGivenDefaultWhenAuthorizationIsRequiredForResources(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', 401),
        ]);

        $resources = (new Client(new HttpTransport('https://mcp.test/mcp')))->resources(default: []);

        $this->assertInstanceOf(Collection::class, $resources);
        $this->assertEmpty($resources);
    }

    public function testRethrowsTheAuthorizationExceptionForResourcesWhenNoDefaultIsGiven(): void
    {
        Http::fake([
            'https://mcp.test/mcp' => Http::response('', 401),
        ]);

        $this->expectException(AuthorizationRequiredException::class);
        (new Client(new HttpTransport('https://mcp.test/mcp')))->resources();
    }

    public function testSendsResourcesReadByUriAndReturnsTextContent(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'contents' => [
                    ['uri' => 'file://readme', 'mimeType' => 'text/plain', 'text' => 'Hello, '],
                    ['uri' => 'file://readme', 'mimeType' => 'text/plain', 'text' => 'world!'],
                ],
            ],
        ]);

        $result = (new Client($transport))->readResource('file://readme');

        $this->assertInstanceOf(ResourceReadResult::class, $result);
        $this->assertCount(2, $result->contents);
        $this->assertSame('Hello, world!', $result->content());
        $this->assertSame('Hello, world!', (string) $result);
        $request = json_decode($transport->sent[2], true);
        $this->assertSame('resources/read', $request['method']);
        $this->assertSame('file://readme', $request['params']['uri']);
    }

    public function testDecodesBlobContentFromAResourcesReadResult(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'contents' => [
                    ['uri' => 'file://logo', 'mimeType' => 'image/png', 'blob' => base64_encode('binary-')],
                    ['uri' => 'file://logo', 'mimeType' => 'image/png', 'blob' => 'not-valid-base64!!'],
                    ['uri' => 'file://logo', 'mimeType' => 'image/png', 'blob' => 123],
                    ['uri' => 'file://logo', 'mimeType' => 'image/png', 'blob' => base64_encode('data')],
                ],
            ],
        ]);

        $result = (new Client($transport))->readResource('file://logo');

        $this->assertSame('binary-data', $result->content());
    }

    public function testReturnsTheMimeTypeFromTheFirstContentItem(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'contents' => [
                    ['uri' => 'file://logo', 'mimeType' => 'image/png', 'blob' => base64_encode('data')],
                ],
            ],
        ]);

        $result = (new Client($transport))->readResource('file://logo');

        $this->assertSame('image/png', $result->mimeType());
    }

    public function testReturnsNullMimeTypeWhenContentsAreEmpty(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => ['contents' => []],
        ]);

        $result = (new Client($transport))->readResource('file://empty');

        $this->assertNull($result->mimeType());
    }

    public function testPreservesMetaFromTheResourcesReadResponse(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'contents' => [['uri' => 'file://readme', 'text' => 'Hi']],
                '_meta' => ['source' => 'cached', 'duration_ms' => 12],
            ],
        ]);

        $result = (new Client($transport))->readResource('file://readme');

        $this->assertSame(['source' => 'cached', 'duration_ms' => 12], $result->meta);
    }

    public function testThrowsWhenAResourcesReadResultHasANonArrayContentsField(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'contents' => 'not-an-array',
            ],
        ]);

        $this->expectException(ClientException::class);
        $this->expectExceptionMessage('Invalid resources/read result from server.');
        (new Client($transport))->readResource('file://readme');
    }

    public function testReturnsAnEmptyResultWhenResourcesReadOmitsContents(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [],
        ]);

        $result = (new Client($transport))->readResource('file://empty');

        $this->assertInstanceOf(ResourceReadResult::class, $result);
        $this->assertSame([], $result->contents);
        $this->assertNull($result->meta);
        $this->assertSame('', $result->content());
        $this->assertSame('', (string) $result);
    }

    public function testFiltersOutNonArrayContentItemsFromAResourcesReadResult(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'contents' => [
                    'not-an-object',
                    ['uri' => 'file://readme', 'text' => 'Hi'],
                ],
            ],
        ]);

        $result = (new Client($transport))->readResource('file://readme');

        $this->assertCount(1, $result->contents);
        $this->assertSame('Hi', $result->content());
    }

    public function testCoercesANonArrayMetaFromResourcesReadToNull(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse();
        $transport->responses[] = json_encode([
            'jsonrpc' => '2.0',
            'id' => 2,
            'result' => [
                'contents' => [['uri' => 'file://readme', 'text' => 'Hi']],
                '_meta' => 'not-an-object',
            ],
        ]);

        $result = (new Client($transport))->readResource('file://readme');

        $this->assertNull($result->meta);
    }
}
