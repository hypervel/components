<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Attributes\Strict;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\HasProviderOptions;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\NamedToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;
use Hypervel\Tests\Ai\TestCase;

use function Hypervel\Ai\agent;

class ToolMappingTest extends TestCase
{
    use AnthropicHelpers;

    public function testToolParametersAreNotWrappedInSchemaDefinition(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $tools = $request->data()['tools'] ?? [];

            foreach ($tools as $tool) {
                if ($tool['name'] === 'FixedNumberGenerator') {
                    $properties = (array) ($tool['input_schema']['properties'] ?? []);

                    return $tool['input_schema']['type'] === 'object'
                        && ! isset($properties['schema_definition']);
                }
            }

            return false;
        });
    }

    public function testToolWithANameMethodEmitsTheDeclaredName(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        (new NamedToolAgent('aliased_tool'))->prompt('Search', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $names = collect($request->data()['tools'] ?? [])->pluck('name')->all();

            return in_array('aliased_tool', $names, true);
        });
    }

    public function testStreamedRequestsStreamFunctionToolInputEagerlyButLeaveProviderToolsAlone(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([$this->messageStart(), $this->messageDelta('end_turn', 1)]),
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        agent(tools: [new NonStrictTool, new WebSearch])->stream('Hi', provider: 'anthropic')->each(fn () => null);

        Http::assertSent(function ($request): bool {
            $tools = collect($request->data()['tools'])->keyBy('name');

            return $tools['NonStrictTool']['eager_input_streaming'] === true
                && ! array_key_exists('eager_input_streaming', $tools['web_search']);
        });
    }

    public function testStreamedRequestsPreserveExplicitEagerInputStreamingSetting(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response(
                body: $this->ssePayload([$this->messageStart(), $this->messageDelta('end_turn', 1)]),
                headers: ['Content-Type' => 'text/event-stream'],
            ),
        ]);

        $agent = new class implements Agent, HasProviderOptions {
            use Promptable;

            /**
             * Get the agent instructions.
             */
            public function instructions(): string
            {
                return 'You are a helpful assistant.';
            }

            /**
             * Get provider-specific request options.
             */
            public function providerOptions(Lab|string $provider): array
            {
                return [
                    'tools' => [[
                        'name' => 'CustomTool',
                        'description' => 'A custom tool.',
                        'input_schema' => ['type' => 'object', 'properties' => (object) []],
                        'eager_input_streaming' => false,
                    ]],
                ];
            }
        };

        $agent->stream('Hi', provider: 'anthropic')->each(fn () => null);

        Http::assertSent(fn ($request): bool => $request->data()['tools'][0]['eager_input_streaming'] === false);
    }

    public function testToolWithStrictAttributeSendsStrictTrue(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [$this->anthropicStrictOrderTool()])->prompt('Order two', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = $request->data()['tools'][0];

            return $tool['strict'] === true
                && $tool['input_schema']['additionalProperties'] === false
                && $tool['input_schema']['required'] === ['quantity'];
        });
    }

    public function testToolWithoutStrictAttributeOmitsStrict(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [new NonStrictTool])->prompt('Hi', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'NonStrictTool');

            return ! array_key_exists('strict', $tool)
                && ! array_key_exists('additionalProperties', $tool['input_schema']);
        });
    }

    public function testStrictToolSchemasDropKeywordsAnthropicStrictModeRejects(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [$this->anthropicStrictOrderTool()])->prompt('Order two', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $quantity = ((array) $request->data()['tools'][0]['input_schema']['properties'])['quantity'];

            return ! array_key_exists('minimum', $quantity)
                && str_contains($quantity['description'] ?? '', 'Must be at least 1.');
        });
    }

    public function testStrictToolsAreSentAsRegularToolsWhenNativeStructuredOutputIsDisabled(): void
    {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [$this->anthropicStrictOrderTool()])->prompt('Order two', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = $request->data()['tools'][0];
            $quantity = ((array) $tool['input_schema']['properties'])['quantity'];

            return ! array_key_exists('strict', $tool)
                && ! array_key_exists('additionalProperties', $tool['input_schema'])
                && $quantity['minimum'] === 1;
        });
    }

    public function testWebSearchToolSendsAllowedDomains(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [(new WebSearch)->allow(['hypervel.org', 'php.net'])])
            ->prompt('Search', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

            return data_get($tool, 'allowed_domains') === ['hypervel.org', 'php.net'];
        });
    }

    public function testWebSearchToolForwardsAnthropicProviderOptionsIntoTheToolPayload(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [
            (new WebSearch)->withProviderOptions(['blocked_domains' => ['spam.com']]),
        ])->prompt('Search', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

            return data_get($tool, 'blocked_domains') === ['spam.com'];
        });
    }

    public function testWebSearchToolSendsUserLocationWhenLocationIsSet(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [(new WebSearch)->location(city: 'Warsaw', country: 'PL')])
            ->prompt('Search', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

            return data_get($tool, 'user_location.type') === 'approximate'
                && data_get($tool, 'user_location.city') === 'Warsaw'
                && data_get($tool, 'user_location.country') === 'PL';
        });
    }

    public function testWebSearchToolOmitsUserLocationWhenNoLocationSet(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [new WebSearch])->prompt('Search', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_search');

            return ! array_key_exists('user_location', $tool);
        });
    }

    public function testWebFetchToolSendsAllowedDomains(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [(new WebFetch)->allow(['hypervel.org', 'php.net'])])
            ->prompt('Fetch', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_fetch');

            return data_get($tool, 'type') === 'web_fetch_20250910'
                && data_get($tool, 'allowed_domains') === ['hypervel.org', 'php.net'];
        });
    }

    public function testWebFetchToolOmitsMaxUsesWhenNoneIsSet(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [new WebFetch])->prompt('Fetch', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_fetch');

            return $tool !== null && ! array_key_exists('max_uses', $tool);
        });
    }

    public function testWebFetchToolForwardsProviderOptions(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        $fetch = (new WebFetch)->withProviderOptions([
            'citations' => ['enabled' => true],
            'max_content_tokens' => 50000,
        ]);

        agent(tools: [$fetch])->prompt('Fetch', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_fetch');

            return data_get($tool, 'citations.enabled') === true
                && data_get($tool, 'max_content_tokens') === 50000;
        });
    }

    public function testWebFetchToolResultSurfacesTheFetchedUrlAsACitation(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_fetch_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    ['type' => 'text', 'text' => "I'll fetch that."],
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_1',
                        'name' => 'web_fetch',
                        'input' => (object) ['url' => 'https://example.com/article'],
                    ],
                    [
                        'type' => 'web_fetch_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => [
                            'type' => 'web_fetch_result',
                            'url' => 'https://example.com/article',
                            'content' => [
                                'type' => 'document',
                                'source' => ['type' => 'text', 'media_type' => 'text/plain', 'data' => 'Full text.'],
                                'title' => 'Article Title',
                                'citations' => ['enabled' => true],
                            ],
                            'retrieved_at' => '2026-08-17T10:30:00Z',
                        ],
                    ],
                    ['type' => 'text', 'text' => 'The article argues X.'],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = agent(tools: [(new WebFetch)->withProviderOptions(['citations' => ['enabled' => true]])])
            ->prompt('Fetch it', provider: 'anthropic');

        $this->assertCount(1, $response->meta->citations);
        $this->assertSame('https://example.com/article', $response->meta->citations[0]->url);
        $this->assertSame('Article Title', $response->meta->citations[0]->title);
    }

    public function testWebFetchToolResultSkipsCitationsForAFailedFetch(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_fetch_2',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'web_fetch_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => ['type' => 'web_fetch_tool_result_error', 'error_code' => 'url_not_accessible'],
                    ],
                    ['type' => 'text', 'text' => "I couldn't reach that page."],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = agent(tools: [new WebFetch])->prompt('Fetch it', provider: 'anthropic');

        $this->assertEmpty($response->meta->citations);
    }

    public function testWebSearchReportsOnlyTheSourcesTheAnswerCites(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'id' => 'msg_search_1',
                'type' => 'message',
                'role' => 'assistant',
                'model' => 'claude-sonnet-4-6',
                'content' => [
                    [
                        'type' => 'server_tool_use',
                        'id' => 'srvtoolu_1',
                        'name' => 'web_search',
                        'input' => (object) ['query' => 'latest laravel version'],
                    ],
                    [
                        'type' => 'web_search_tool_result',
                        'tool_use_id' => 'srvtoolu_1',
                        'content' => [
                            ['type' => 'web_search_result', 'url' => 'https://laravel.com/docs/releases', 'title' => 'Release Notes', 'encrypted_content' => 'abc', 'page_age' => null],
                            ['type' => 'web_search_result', 'url' => 'https://laravel-news.com/laravel-13', 'title' => 'Laravel 13', 'encrypted_content' => 'def', 'page_age' => null],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => 'The latest version is Laravel 13.',
                        'citations' => [
                            ['type' => 'web_search_result_location', 'url' => 'https://laravel-news.com/laravel-13', 'title' => 'Laravel 13', 'cited_text' => 'Laravel 13', 'encrypted_index' => 'xyz'],
                        ],
                    ],
                ],
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            ]),
        ]);

        $response = agent(tools: [new WebSearch])->prompt('Search it', provider: 'anthropic');

        $this->assertSame(
            [['https://laravel-news.com/laravel-13', 'Laravel 13']],
            $response->meta->citations->map(fn ($citation): array => [$citation->url, $citation->title])->all(),
        );
    }

    public function testWebFetchToolForwardsACustomMaxUses(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [(new WebFetch)->max(3)])->prompt('Fetch', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'web_fetch');

            return data_get($tool, 'max_uses') === 3;
        });
    }

    public function testEmptySchemaStillIncludesInputSchemaWithTypeObject(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('The number is 42'),
        ]);

        (new ToolUsingAgent(fixed: true))->prompt(
            'Generate a number',
            provider: 'anthropic',
        );

        Http::assertSent(function ($request): bool {
            $tools = $request->data()['tools'] ?? [];

            foreach ($tools as $tool) {
                if ($tool['name'] === 'FixedNumberGenerator') {
                    return isset($tool['input_schema'])
                        && $tool['input_schema']['type'] === 'object'
                        && isset($tool['input_schema']['properties']);
                }
            }

            return false;
        });
    }

    public function testCodeExecutionToolSendsDatedTypeAndName(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        agent(tools: [new CodeExecution])->prompt('Run some code', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $tool = collect($request->data()['tools'] ?? [])->firstWhere('name', 'code_execution');

            return data_get($tool, 'type') === 'code_execution_20260120';
        });
    }

    /**
     * Create a strict tool whose schema uses a keyword Anthropic's strict mode rejects.
     */
    protected function anthropicStrictOrderTool(): Tool
    {
        return new #[Strict] class implements Tool {
            /**
             * Get the tool description.
             */
            public function description(): string
            {
                return 'Orders a number of items.';
            }

            /**
             * Handle the tool invocation.
             */
            public function handle(Request $request): string
            {
                return 'ok';
            }

            /**
             * Get the tool's input schema.
             */
            public function schema(JsonSchema $schema): array
            {
                return [
                    'quantity' => $schema->integer()->min(1)->required(),
                    'note' => $schema->string(),
                ];
            }
        };
    }
}
