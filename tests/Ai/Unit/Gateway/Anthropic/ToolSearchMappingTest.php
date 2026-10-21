<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\Anthropic;

use Hypervel\Ai\Contracts\Providers\SupportsToolSearch;
use Hypervel\Ai\Contracts\Providers\SupportsWebSearch;
use Hypervel\Ai\Gateway\Anthropic\Concerns\MapsTools;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Ai\Providers\Tools\WebSearch;
use Hypervel\Tests\Ai\Fixtures\Tools\DeferredTool;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;
use Hypervel\Tests\TestCase;

function anthropicToolSearchMapper(): object
{
    return new class {
        use MapsTools;

        /**
         * Map tools into the provider's request format.
         */
        public function map(array $tools, Provider $provider): array
        {
            return $this->mapTools($tools, $provider);
        }
    };
}

function anthropicToolSearchProvider(): Provider
{
    return new class extends Provider implements SupportsToolSearch {
        /**
         * Create a provider without a gateway.
         */
        public function __construct()
        {
        }
    };
}

class ToolSearchMappingTest extends TestCase
{
    public function testEmitsTheRegexToolSearchEntryAndDefersTheToolsNestedInTheToolSearchTool(): void
    {
        $mapped = anthropicToolSearchMapper()->map(
            [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])],
            anthropicToolSearchProvider(),
        );

        $search = collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119');

        $this->assertSame([
            'type' => 'tool_search_tool_regex_20251119',
            'name' => 'tool_search_tool_regex',
        ], $search);
        $this->assertArrayNotHasKey('defer_loading', $search);

        $deferred = collect($mapped)->firstWhere('defer_loading', true);
        $nonDeferred = collect($mapped)->filter(
            fn ($tool): bool => isset($tool['input_schema']) && ! isset($tool['defer_loading'])
        );

        $this->assertNotNull($deferred);
        $this->assertStringContainsString('deferred', $deferred['description']);
        $this->assertCount(1, $nonDeferred);
    }

    public function testEmitsTheBm25ToolSearchEntryWhenThatStrategyIsPassedAsAParameter(): void
    {
        $mapped = anthropicToolSearchMapper()->map(
            [new NonStrictTool, new ToolSearch(tools: [new DeferredTool], strategy: 'bm25')],
            anthropicToolSearchProvider(),
        );

        $this->assertSame([
            'type' => 'tool_search_tool_bm25_20251119',
            'name' => 'tool_search_tool_bm25',
        ], collect($mapped)->firstWhere('type', 'tool_search_tool_bm25_20251119'));
    }

    public function testDoesNotLeakTheStrategyParameterOntoTheToolSearchEntry(): void
    {
        $mapped = anthropicToolSearchMapper()->map(
            [new NonStrictTool, new ToolSearch(tools: [new DeferredTool], strategy: 'regex')],
            anthropicToolSearchProvider(),
        );

        $this->assertArrayNotHasKey('strategy', collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119'));
    }

    public function testForwardsProviderOptionsOntoTheToolSearchEntry(): void
    {
        $search = (new ToolSearch(tools: [new DeferredTool]))
            ->withProviderOptions(['cache_control' => ['type' => 'ephemeral']]);

        $mapped = anthropicToolSearchMapper()->map([new NonStrictTool, $search], anthropicToolSearchProvider());

        $this->assertSame([
            'type' => 'tool_search_tool_regex_20251119',
            'name' => 'tool_search_tool_regex',
            'cache_control' => ['type' => 'ephemeral'],
        ], collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119'));
    }

    public function testDoesNotEmitAToolSearchEntryWhenNoToolSearchToolIsPresent(): void
    {
        $mapped = anthropicToolSearchMapper()->map(
            [new NonStrictTool],
            anthropicToolSearchProvider(),
        );

        $this->assertCount(1, $mapped);
        $this->assertFalse(collect($mapped)->contains(fn ($tool): bool => isset($tool['defer_loading'])));
    }

    public function testMapsAToolSearchWhoseOnlyToolsAreDeferredSinceTheSearchEntryItselfIsTheNonDeferredTool(): void
    {
        $mapped = anthropicToolSearchMapper()->map(
            [new ToolSearch(tools: [new DeferredTool, new DeferredTool])],
            anthropicToolSearchProvider(),
        );

        $this->assertNotNull(collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119'));
        $this->assertCount(2, collect($mapped)->where('defer_loading', true));
    }

    public function testMapsAToolSearchAlongsideAServerTool(): void
    {
        $provider = new class extends Provider implements SupportsToolSearch, SupportsWebSearch {
            /**
             * Create a provider without a gateway.
             */
            public function __construct()
            {
            }

            /**
             * Get the web search options.
             */
            public function webSearchToolOptions(WebSearch $search): array
            {
                return [];
            }
        };

        $mapped = anthropicToolSearchMapper()->map(
            [new WebSearch, new ToolSearch(tools: [new DeferredTool])],
            $provider,
        );

        $this->assertNotNull(collect($mapped)->firstWhere('type', 'tool_search_tool_regex_20251119'));
        $this->assertNotNull(collect($mapped)->firstWhere('type', 'web_search_20250305'));
    }

    public function testSkipsAnEmptyToolSearchToolWithoutEmittingASearchEntry(): void
    {
        $mapped = anthropicToolSearchMapper()->map(
            [new NonStrictTool, new ToolSearch],
            anthropicToolSearchProvider(),
        );

        $this->assertCount(1, $mapped);
        $this->assertFalse(collect($mapped)->contains(fn ($tool): bool => str_starts_with($tool['type'] ?? '', 'tool_search')));
    }
}
