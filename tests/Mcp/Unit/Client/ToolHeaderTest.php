<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Client;

use Hypervel\Mcp\Client;
use Hypervel\Mcp\Client\Primitives\Tool;
use Hypervel\Mcp\Enums\ProtocolVersion;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Exceptions\MirroredParameterException;
use Hypervel\Support\Facades\Log;
use Hypervel\Tests\Mcp\Fixtures\Client\FakeTransport;
use Hypervel\Tests\Mcp\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\TestWith;

use function Hypervel\Tests\Mcp\Fixtures\discoverResponse;
use function Hypervel\Tests\Mcp\Fixtures\initializeResponse;
use function Hypervel\Tests\Mcp\Fixtures\methodNotFoundResponse;
use function Hypervel\Tests\Mcp\Fixtures\toolCallResponse;

/**
 * Create a modern tool-list response.
 */
function toolsListResponse(int $id, array $tools): string
{
    return (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => ['resultType' => 'complete', 'tools' => $tools],
    ]);
}

/**
 * Describe a tool with a mirrored region parameter.
 */
function annotatedTool(string $header = 'Region'): array
{
    return [
        'name' => 'execute_sql',
        'description' => 'Runs SQL',
        'inputSchema' => [
            'type' => 'object',
            'properties' => [
                'region' => ['type' => 'string', 'x-mcp-header' => $header],
                'query' => ['type' => 'string'],
            ],
        ],
    ];
}

class ToolHeaderTest extends TestCase
{
    public function testMirrorsAnnotatedParametersIntoHeadersOnAToolCall(): void
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = discoverResponse();
        $transport->responses[] = toolsListResponse(2, [annotatedTool()]);
        $transport->responses[] = toolCallResponse(3, 'done');

        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST);
        $tool = $client->tools()->get('execute_sql');

        $result = $client->callTool($tool, ['region' => 'us-west1', 'query' => 'select 1']);

        $this->assertInstanceOf(Tool::class, $tool);
        $this->assertCount(1, $tool->mirroredParameters());
        $this->assertSame('done', $result->text());
        $this->assertSame([
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'execute_sql',
            'Mcp-Param-Region' => 'us-west1',
        ], $transport->sentHeaders[2]);
    }

    public function testMirrorsParametersWhenCallingThroughTheToolPrimitive(): void
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = discoverResponse();
        $transport->responses[] = toolsListResponse(2, [annotatedTool()]);
        $transport->responses[] = toolCallResponse(3, 'done');

        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST);

        $client->tools()->get('execute_sql')->call(['region' => 'us-west1', 'query' => 'select 1']);

        $this->assertSame([
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'execute_sql',
            'Mcp-Param-Region' => 'us-west1',
        ], $transport->sentHeaders[2]);
    }

    public function testSendsOnlyTheStandardHeadersWhenCallingAToolByName(): void
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = discoverResponse();
        $transport->responses[] = toolCallResponse(2, 'done');

        (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST)->callTool('execute_sql', ['region' => 'us-west1']);

        $this->assertSame([
            'Mcp-Method' => 'tools/call',
            'Mcp-Name' => 'execute_sql',
        ], $transport->sentHeaders[1]);
        $this->assertArrayNotHasKey('Mcp-Param-Region', $transport->sentHeaders[1]);
    }

    public function testSendsNoMirroredHeadersOnTheLegacyProtocolEra(): void
    {
        $transport = new FakeTransport;
        $transport->responses[] = initializeResponse(1, ProtocolVersion::V2025_11_25->value);
        $transport->responses[] = toolsListResponse(2, [annotatedTool()]);
        $transport->responses[] = toolCallResponse(3, 'done');

        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::V2025_11_25);

        $client->tools()->get('execute_sql')->call(['region' => 42]);

        $this->assertEmpty($transport->sentHeaders[2]);
    }

    public function testExcludesAToolWhoseAnnotationIsInvalidFromTheToolList(): void
    {
        Log::spy();

        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = discoverResponse();
        $transport->responses[] = toolsListResponse(2, [
            annotatedTool('Bad Header'),
            ['name' => 'plain', 'description' => 'Fine', 'inputSchema' => ['type' => 'object']],
        ]);

        $tools = (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST)->tools();

        $this->assertCount(1, $tools);
        $this->assertArrayHasKey('plain', $tools);
        $this->assertArrayNotHasKey('execute_sql', $tools);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message): bool => str_contains($message, 'execute_sql') && str_contains($message, 'not a valid header name token'),
        );
    }

    public function testRelistsAndRetriesOnceWhenTheServerReportsAHeaderMismatch(): void
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = discoverResponse();
        $transport->responses[] = headerMismatchResponse(2);
        $transport->responses[] = toolsListResponse(3, [annotatedTool()]);
        $transport->responses[] = toolCallResponse(4, 'done');

        $result = (new Client($transport))
            ->withProtocolVersion(ProtocolVersion::LATEST)
            ->callTool('execute_sql', ['region' => 'us-west1', 'query' => 'select 1']);

        $this->assertSame('done', $result->text());
        $this->assertCount(4, $transport->sent);
        $this->assertArrayNotHasKey('Mcp-Param-Region', $transport->sentHeaders[1]);
        $this->assertSame('us-west1', $transport->sentHeaders[3]['Mcp-Param-Region']);
        $this->assertSame(['tools/call', 'tools/list', 'tools/call'], sentMethods($transport));
    }

    #[TestWith(['public'])]
    #[TestWith(['private'])]
    public function testHeaderMismatchRefreshesACachedToolDefinition(string $scope): void
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = discoverResponse();
        $cached = json_decode(toolsListResponse(2, [annotatedTool('OldRegion')]), true);
        $cached['result'] += ['ttlMs' => 60000, 'cacheScope' => $scope];
        $transport->responses[] = json_encode($cached);
        $transport->responses[] = headerMismatchResponse(3);
        $transport->responses[] = toolsListResponse(4, [annotatedTool()]);
        $transport->responses[] = toolCallResponse(5, 'done');

        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST)->withCache();
        $tool = $client->tools()->get('execute_sql');

        $this->assertSame('done', $tool->call(['region' => 'us-west1'])->text());
        $this->assertSame(['tools/list', 'tools/call', 'tools/list', 'tools/call'], sentMethods($transport));
        $this->assertSame('us-west1', $transport->sentHeaders[4]['Mcp-Param-Region']);
        $this->assertArrayNotHasKey('Mcp-Param-OldRegion', $transport->sentHeaders[4]);
    }

    #[TestWith([['region' => 42], MirroredParameterException::class])]
    #[TestWith([['region' => INF], JsonException::class])]
    public function testInvalidLocalArgumentsDoNotDisconnectAHealthySession(array $arguments, string $exceptionClass): void
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = discoverResponse();
        $transport->responses[] = toolsListResponse(2, [annotatedTool()]);
        $transport->responses[] = toolCallResponse(4, 'done');
        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST);
        $tool = $client->tools()->get('execute_sql');

        try {
            $tool->call($arguments);
            $this->fail('Invalid local arguments must fail before sending.');
        } catch (JsonException|MirroredParameterException $exception) {
            $this->assertInstanceOf($exceptionClass, $exception);
        }

        $this->assertTrue($client->connected());
        $this->assertTrue($transport->connected);
        $this->assertCount(2, $transport->sent);
        $this->assertSame('done', $tool->call(['region' => 'us-west1'])->text());
        $this->assertCount(3, $transport->sent);
    }

    public function testDoesNotRetryWhenTheRefreshedDefinitionMirrorsNothingNew(): void
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = discoverResponse();
        $transport->responses[] = headerMismatchResponse(2);
        $transport->responses[] = toolsListResponse(3, [
            ['name' => 'execute_sql', 'description' => 'Runs SQL', 'inputSchema' => ['type' => 'object']],
        ]);

        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST);

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionCode(-32020);
        $this->expectExceptionMessage('Header mismatch');
        try {
            $client->callTool('execute_sql', ['region' => 'us-west1']);
        } finally {
            $this->assertCount(3, $transport->sent);
        }
    }

    public function testDoesNotRetryAToolCallThatFailsForAnyOtherReason(): void
    {
        $transport = new FakeTransport;
        $transport->negotiates = true;
        $transport->responses[] = discoverResponse();
        $transport->responses[] = methodNotFoundResponse(2);

        $client = (new Client($transport))->withProtocolVersion(ProtocolVersion::LATEST);

        $this->expectException(JsonRpcException::class);
        $this->expectExceptionMessage('Method not found');
        try {
            $client->callTool('execute_sql', ['region' => 'us-west1']);
        } finally {
            $this->assertCount(2, $transport->sent);
            $this->assertSame(['tools/call'], sentMethods($transport));
        }
    }
}

/**
 * List application methods sent by the transport.
 */
function sentMethods(FakeTransport $transport): array
{
    return collect($transport->sent)
        ->map(fn (string $frame): mixed => json_decode($frame, true)['method'] ?? null)
        ->reject(fn (mixed $method): bool => in_array($method, [null, 'server/discover', 'initialize'], true))
        ->values()
        ->all();
}

/**
 * Create a protocol header-mismatch response.
 */
function headerMismatchResponse(int $id): string
{
    return (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => -32020, 'message' => 'Header mismatch: the [Mcp-Param-Region] header is required.'],
    ]);
}
