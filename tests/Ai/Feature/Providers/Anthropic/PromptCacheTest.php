<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Ai\Attributes\CacheInstructions;
use Hypervel\Ai\Attributes\CacheToolDefinitions;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\PromptCacheAgent;
use Hypervel\Tests\Ai\Fixtures\Agents\PromptCacheStructuredAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;
use InvalidArgumentException;

class PromptCacheTest extends TestCase
{
    use AnthropicHelpers;

    /**
     * Fake the provider response.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'api.anthropic.com/*' => $this->fakeTextResponse(),
        ]);
    }

    public function testCacheInstructionsAttributeConvertsInstructionsToACachedBlock(): void
    {
        (new #[CacheInstructions] class(withTools: false) extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['system'] === [[
                'type' => 'text',
                'text' => 'You are a helpful assistant that generates numbers.',
                'cache_control' => ['type' => 'ephemeral'],
            ]];
        });
    }

    public function testCacheToolDefinitionsAttributeStampsTheLastTool(): void
    {
        (new #[CacheToolDefinitions] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['system'] === 'You are a helpful assistant that generates numbers.'
                && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
        });
    }

    public function testBothTargetsMayBeCachedTogether(): void
    {
        (new #[CacheInstructions] #[CacheToolDefinitions] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return isset($body['system'][0]['cache_control'])
                && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
        });
    }

    public function testTheSyntheticStructuredOutputToolReceivesTheBreakpoint(): void
    {
        config(['ai.providers.anthropic' => [
            ...config('ai.providers.anthropic'),
            'use_native_structured_output' => false,
        ]]);

        Http::fake([
            'api.anthropic.com/*' => $this->fakeSyntheticStructuredResponse(['symbol' => 'Fe']),
        ]);

        (new PromptCacheStructuredAgent)->prompt('Iron', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return Arr::last($body['tools'])['name'] === 'output_structured_data'
                && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
        });
    }

    public function testAnAgentWithoutCacheAttributesLeavesThePayloadUntouched(): void
    {
        (new PromptCacheAgent)->prompt('Hi', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return is_string($body['system'])
                && ! array_key_exists('cache_control', Arr::last($body['tools']));
        });
    }

    public function testProviderOptionsStillMergeAlongsideCacheAttributes(): void
    {
        (new #[CacheInstructions] class(options: ['thinking' => ['type' => 'enabled', 'budget_tokens' => 10000]]) extends PromptCacheAgent {})
            ->prompt('Hi', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['thinking']['budget_tokens'] === 10000
                && isset($body['system'][0]['cache_control']);
        });
    }

    public function testATargetMayRequestTheExtendedTtl(): void
    {
        (new #[CacheInstructions('5m')] #[CacheToolDefinitions('1h')] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['system'][0]['cache_control'] === ['type' => 'ephemeral', 'ttl' => '5m']
                && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral', 'ttl' => '1h'];
        });
    }

    public function testAnOmittedTtlUsesTheProviderDefault(): void
    {
        (new #[CacheInstructions] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => $request->data()['system'][0]['cache_control'] === ['type' => 'ephemeral']);
    }

    public function testALongerInstructionsTtlRequiresTheToolsCacheToUseTheSameTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new #[CacheInstructions('1h')] #[CacheToolDefinitions('5m')] class extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');
    }

    public function testALongerAutomaticCacheTtlRequiresExplicitBreakpointsToUseTheSameTtl(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new #[CacheInstructions] class(options: ['cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']]) extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');
    }

    public function testBreakpointsSurviveProviderOptionsThatOverrideTheSameKeys(): void
    {
        (new #[CacheInstructions] #[CacheToolDefinitions] class(options: ['system' => 'Overridden instructions.', 'tools' => [['name' => 'custom', 'description' => '', 'input_schema' => ['type' => 'object']]]]) extends PromptCacheAgent {})->prompt('Hi', provider: 'anthropic');

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return $body['system'] === [[
                'type' => 'text',
                'text' => 'Overridden instructions.',
                'cache_control' => ['type' => 'ephemeral'],
            ]]
                && Arr::last($body['tools'])['name'] === 'custom'
                && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
        });
    }

    public function testTheToolsTargetIsANoOpWhenTheRequestCarriesNoTools(): void
    {
        (new PromptCacheStructuredAgent)->prompt('Iron', provider: 'anthropic');

        Http::assertSent(fn ($request): bool => ! array_key_exists('tools', $request->data()));
    }

    public function testTheStreamingPathStampsTheSameBreakpoints(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response($this->ssePayload([
                $this->messageStart(),
                $this->contentBlockStart(0, ['type' => 'text', 'text' => '']),
                $this->contentBlockDelta(0, ['type' => 'text_delta', 'text' => 'Hi']),
                $this->contentBlockStop(0),
                $this->messageDelta('end_turn', 5),
            ]), 200, ['Content-Type' => 'text/event-stream']),
        ]);

        $this->collectStreamEvents(new #[CacheInstructions] #[CacheToolDefinitions] class extends PromptCacheAgent {});

        Http::assertSent(function ($request): bool {
            $body = $request->data();

            return isset($body['system'][0]['cache_control'])
                && Arr::last($body['tools'])['cache_control'] === ['type' => 'ephemeral'];
        });
    }
}
