<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AnthropicToolSearchAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;

class ToolSearchTest extends TestCase
{
    use AnthropicHelpers;

    public function testAnAgentWithAToolSearchToolEmitsTheRegexToolSearchEntryAndDefersItsNestedTools(): void
    {
        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse('ok'),
        ]);

        (new AnthropicToolSearchAgent)->prompt('Hi');

        Http::assertSent(function ($request): bool {
            $tools = collect($request->data()['tools'] ?? []);

            $deferred = $tools->firstWhere('name', 'DeferredTool');
            $plain = $tools->firstWhere('name', 'NonStrictTool');

            return $tools->contains(fn ($tool): bool => ($tool['type'] ?? null) === 'tool_search_tool_regex_20251119')
                && ($deferred['defer_loading'] ?? false) === true
                && ! isset($plain['defer_loading']);
        });
    }
}
