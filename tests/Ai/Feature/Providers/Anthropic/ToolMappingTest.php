<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Providers\Tools\CodeExecution;
use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\NamedToolAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\ToolUsingAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
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
}
