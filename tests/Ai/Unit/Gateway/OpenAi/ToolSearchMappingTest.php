<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Unit\Gateway\OpenAi;

use Hypervel\Ai\Contracts\Providers\SupportsToolSearch;
use Hypervel\Ai\Gateway\OpenAi\Concerns\MapsTools;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Ai\Providers\Tools\WebFetch;
use Hypervel\Tests\Ai\Fixtures\Tools\DeferredTool;
use Hypervel\Tests\Ai\Fixtures\Tools\NonStrictTool;
use Hypervel\Tests\TestCase;
use LogicException;
use RuntimeException;

function openAiToolSearchMapper(): object
{
    return new class {
        use MapsTools;

        /**
         * Map tools to the provider request.
         */
        public function map(array $tools, Provider $provider, bool $stateless = false): array
        {
            return $this->mapTools($tools, $provider, $stateless);
        }
    };
}

function openAiToolSearchProvider(): Provider
{
    return new class extends Provider implements SupportsToolSearch {
        /**
         * Configure the provider driver.
         */
        public function __construct()
        {
            $this->config = ['driver' => 'openai'];
        }

        /**
         * Get the provider name.
         */
        public function name(): string
        {
            return 'openai';
        }
    };
}

class ToolSearchMappingTest extends TestCase
{
    public function testEmitsTheToolSearchEntryAndDefersTheToolsNestedInTheToolSearchTool(): void
    {
        $mapped = openAiToolSearchMapper()->map(
            [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])],
            openAiToolSearchProvider(),
        );

        $search = collect($mapped)->firstWhere('type', 'tool_search');
        $deferred = collect($mapped)->firstWhere('defer_loading', true);
        $nonDeferred = collect($mapped)->filter(
            fn (array $tool): bool => ($tool['type'] ?? null) === 'function' && ! isset($tool['defer_loading'])
        );

        $this->assertSame(['type' => 'tool_search'], $search);
        $this->assertNotNull($deferred);
        $this->assertStringContainsString('deferred', $deferred['description']);
        $this->assertCount(1, $nonDeferred);
    }

    public function testThrowsForAProviderToolOpenAiCannotMapInsteadOfEmittingAnEmptyEntry(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not support the [WebFetch] tool');
        openAiToolSearchMapper()->map([new WebFetch], openAiToolSearchProvider());
    }

    public function testThrowsWhenResponseStorageIsDisabledBecauseHostedSearchRequiresStoredResponses(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('store=false');
        openAiToolSearchMapper()->map(
            [new NonStrictTool, new ToolSearch(tools: [new DeferredTool])],
            openAiToolSearchProvider(),
            stateless: true,
        );
    }

    public function testForwardsProviderOptionsOntoTheToolSearchEntry(): void
    {
        $search = (new ToolSearch(tools: [new DeferredTool]))
            ->withProviderOptions(['foo' => 'bar']);

        $mapped = openAiToolSearchMapper()->map([new NonStrictTool, $search], openAiToolSearchProvider());

        $this->assertSame(['type' => 'tool_search', 'foo' => 'bar'], collect($mapped)->firstWhere('type', 'tool_search'));
    }

    public function testSkipsAnEmptyToolSearchToolWithoutEmittingAToolSearchEntry(): void
    {
        $mapped = openAiToolSearchMapper()->map(
            [new NonStrictTool, new ToolSearch],
            openAiToolSearchProvider(),
        );

        $this->assertCount(1, $mapped);
        $this->assertNotContains('tool_search', collect($mapped)->pluck('type'));
    }

    public function testDoesNotEmitAToolSearchEntryWhenNoToolSearchToolIsPresent(): void
    {
        $mapped = openAiToolSearchMapper()->map(
            [new NonStrictTool, new DeferredTool],
            openAiToolSearchProvider(),
        );

        $this->assertCount(2, $mapped);
        $this->assertNotContains('tool_search', collect($mapped)->pluck('type'));
        $this->assertFalse(collect($mapped)->contains(fn (array $tool): bool => isset($tool['defer_loading'])));
    }
}
