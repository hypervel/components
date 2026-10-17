<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Fixtures;

use Hypervel\Http\Client\ResponseSequence;
use Hypervel\Support\Facades\Http;
use stdClass;

/**
 * Return the modern protocol metadata.
 */
function protocolMeta(): array
{
    return [
        'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
        'io.modelcontextprotocol/clientCapabilities' => [],
    ];
}

/**
 * Return the HTTP headers describing a protocol message.
 */
function mcpHeaders(array $message): array
{
    $params = $message['params'] ?? [];

    $name = match ($message['method']) {
        'tools/call', 'prompts/get' => $params['name'] ?? null,
        'resources/read' => $params['uri'] ?? null,
        default => null,
    };

    return array_filter([
        'MCP-Protocol-Version' => '2026-07-28',
        'Mcp-Method' => $message['method'],
        'Mcp-Name' => $name,
    ], fn (?string $value): bool => $value !== null);
}

/**
 * Create a legacy initialization message.
 */
function initializeMessage(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 456,
        'method' => 'initialize',
        'params' => [],
    ];
}

/**
 * Create a discovery message.
 */
function discoverMessage(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 456,
        'method' => 'server/discover',
        'params' => [
            '_meta' => protocolMeta(),
        ],
    ];
}

/**
 * Return the example server's discovery response.
 */
function expectedDiscoverResponse(): array
{
    $server = new ExampleServer(new ArrayTransport);

    [$capabilities, $instructions] = (fn (): array => [
        $this->capabilities,
        $this->instructions,
    ])->call($server);

    return [
        'jsonrpc' => '2.0',
        'id' => 456,
        'result' => [
            'resultType' => 'complete',
            'ttlMs' => 0,
            'cacheScope' => 'private',
            'supportedVersions' => ['2026-07-28'],
            'capabilities' => $capabilities,
            'instructions' => $instructions,
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => [
                    'name' => 'Hypervel MCP Server',
                    'version' => '0.0.1',
                ],
            ],
        ],
    ];
}

/**
 * Encode a legacy initialization response.
 */
function initializeResponse(int $id = 1, string $version = '2025-11-25'): string
{
    return json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'protocolVersion' => $version,
            'capabilities' => new stdClass,
            'serverInfo' => ['name' => 'Test Server', 'version' => '1.0.0'],
        ],
    ]);
}

/**
 * Encode a discovery response.
 *
 * @param array<int, string> $supportedVersions
 */
function discoverResponse(int $id = 1, array $supportedVersions = ['2026-07-28']): string
{
    return json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'resultType' => 'complete',
            'supportedVersions' => $supportedVersions,
            'capabilities' => ['tools' => ['listChanged' => false]],
            'instructions' => 'Be nice.',
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => ['name' => 'Test Server', 'version' => '1.0.0'],
            ],
        ],
    ]);
}

/**
 * Fake an endpoint that rejects modern discovery.
 */
function legacyEndpoint(): ResponseSequence
{
    return Http::fakeSequence()->push('Bad Request', 400);
}

/**
 * Encode a method-not-found response.
 */
function methodNotFoundResponse(int $id = 1): string
{
    return json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'error' => ['code' => -32601, 'message' => 'Method not found.'],
    ]);
}

/**
 * Encode a text tool result.
 */
function toolCallResponse(int $id, string $text): string
{
    return (string) json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => false,
        ],
    ]);
}

/**
 * Encode a ping response.
 */
function pingResponse(int $id): string
{
    return json_encode([
        'jsonrpc' => '2.0',
        'id' => $id,
        'result' => new stdClass,
    ]);
}

/**
 * Create a tool-list message.
 */
function listToolsMessage(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/list',
        'params' => [
            '_meta' => protocolMeta(),
        ],
    ];
}

/**
 * Return the example server's tool list.
 */
function expectedListToolsResponse(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'resultType' => 'complete',
            'ttlMs' => 0,
            'cacheScope' => 'private',
            'tools' => [
                [
                    'name' => 'say-hi-tool',
                    'description' => 'This tool says hello to a person',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => [
                                'type' => 'string',
                                'description' => 'The name of the person to greet',
                            ],
                        ],
                        'required' => ['name'],
                    ],
                    'annotations' => ['title' => 'Say Hi Tool'],
                    'title' => 'Say Hi Tool',
                ],
                [
                    'name' => 'streaming-tool',
                    'description' => 'A tool that streams multiple responses.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'count' => [
                                'type' => 'integer',
                                'description' => 'Number of messages to stream.',
                            ],
                        ],
                        'required' => ['count'],
                    ],
                    'annotations' => ['title' => 'Streaming Tool'],
                    'title' => 'Streaming Tool',
                ],
            ],
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => [
                    'name' => 'Hypervel MCP Server',
                    'version' => '0.0.1',
                ],
            ],
        ],
    ];
}

/**
 * Create a resource-list message.
 */
function listResourcesMessage(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'resources/list',
        'params' => [
            '_meta' => protocolMeta(),
        ],
    ];
}

/**
 * Return the example server's resource list.
 */
function expectedListResourcesResponse(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'resultType' => 'complete',
            'ttlMs' => 0,
            'cacheScope' => 'private',
            'resources' => [
                [
                    'name' => 'last-log-line-resource',
                    'title' => 'Last Log Line Resource',
                    'description' => 'The last line of the log file',
                    'uri' => 'file://resources/last-log-line-resource',
                    'mimeType' => 'text/plain',
                ],
                [
                    'name' => 'daily-plan-resource',
                    'title' => 'Daily Plan Resource',
                    'description' => 'The plan for the day',
                    'uri' => 'file://resources/daily-plan.md',
                    'mimeType' => 'text/markdown',
                ],
                [
                    'name' => 'recent-meeting-recording-resource',
                    'title' => 'Recent Meeting Recording Resource',
                    'description' => 'The most recent meeting recording',
                    'uri' => 'file://resources/recent-meeting-recording.mp4',
                    'mimeType' => 'video/mp4',
                ],
            ],
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => [
                    'name' => 'Hypervel MCP Server',
                    'version' => '0.0.1',
                ],
            ],
        ],
    ];
}

/**
 * Create a greeting tool invocation.
 */
function callToolMessage(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'tools/call',
        'params' => [
            'name' => 'say-hi-tool',
            'arguments' => [
                'name' => 'John Doe',
            ],
            '_meta' => protocolMeta(),
        ],
    ];
}

/**
 * Create a resource-read message.
 */
function readResourceMessage(string $uri = 'file://resources/last-log-line-resource'): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 123,
        'method' => 'resources/read',
        'params' => [
            'uri' => $uri,
            '_meta' => protocolMeta(),
        ],
    ];
}

/**
 * Return the log resource's read response.
 */
function expectedReadResourceResponse(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 123,
        'result' => [
            'resultType' => 'complete',
            'ttlMs' => 0,
            'cacheScope' => 'private',
            'contents' => [[
                'text' => '2025-07-02 12:00:00 Error: Something went wrong.',
                'uri' => 'file://resources/last-log-line-resource',
                'mimeType' => 'text/plain',
            ]],
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => [
                    'name' => 'Hypervel MCP Server',
                    'version' => '0.0.1',
                ],
            ],
        ],
    ];
}

/**
 * Return the greeting tool's response.
 */
function expectedCallToolResponse(): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 1,
        'result' => [
            'resultType' => 'complete',
            'content' => [[
                'type' => 'text',
                'text' => 'Hello, John Doe!',
            ]],
            'isError' => false,
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => [
                    'name' => 'Hypervel MCP Server',
                    'version' => '0.0.1',
                ],
            ],
        ],
    ];
}

/**
 * Create a streaming tool invocation.
 */
function callStreamingToolMessage(int $count = 2): array
{
    return [
        'jsonrpc' => '2.0',
        'id' => 2,
        'method' => 'tools/call',
        'params' => [
            'name' => 'streaming-tool',
            'arguments' => [
                'count' => $count,
            ],
            '_meta' => protocolMeta(),
        ],
    ];
}

/**
 * Return the streaming tool's progress and final response.
 */
function expectedStreamingToolResponse(int $count = 2): array
{
    $messages = [];

    for ($i = 1; $i <= $count; ++$i) {
        $messages[] = [
            'jsonrpc' => '2.0',
            'method' => 'stream/progress',
            'params' => ['progress' => $i / $count * 100, 'message' => "Processing item {$i} of {$count}"],
        ];
    }

    $messages[] = [
        'jsonrpc' => '2.0',
        'id' => 2,
        'result' => [
            'resultType' => 'complete',
            'content' => [['type' => 'text', 'text' => "Finished streaming {$count} messages."]],
            'isError' => false,
            '_meta' => [
                'io.modelcontextprotocol/serverInfo' => [
                    'name' => 'Hypervel MCP Server',
                    'version' => '0.0.1',
                ],
            ],
        ],
    ];

    return $messages;
}

/**
 * Decode messages from an SSE response.
 */
function parseJsonRpcMessagesFromSseStream(string $content): array
{
    $messages = [];

    foreach (explode("\n\n", trim($content)) as $event) {
        $event = trim($event);

        if ($event === '') {
            continue;
        }

        $messages[] = json_decode(trim(substr($event, strlen('data: '))), true);
    }

    return $messages;
}

/**
 * Encode protocol frames as an SSE response.
 */
function sseStream(array $frames): string
{
    return collect($frames)
        ->map(fn (array $frame): string => 'data: ' . json_encode($frame) . "\n\n")
        ->implode('');
}

/**
 * Decode protocol messages from standard output.
 */
function parseJsonRpcMessagesFromStdout(string $output): array
{
    $jsonMessages = array_filter(explode("\n", trim($output)));

    $messages = [];

    foreach ($jsonMessages as $jsonMessage) {
        if (empty($jsonMessage)) {
            continue;
        }

        $messages[] = json_decode($jsonMessage, true);
    }

    return $messages;
}
