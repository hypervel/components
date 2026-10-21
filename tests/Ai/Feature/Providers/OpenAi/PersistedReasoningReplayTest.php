<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\OpenAi;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\RememberingToolUsingAgent;
use Hypervel\Tests\Ai\TestCase;

function openAiReasoningToolTurn(): PromiseInterface
{
    return Http::response([
        'id' => 'resp_tool_1',
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'encrypted_content' => 'enc-blob-1'],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'FixedNumberGenerator', 'arguments' => '{}', 'status' => 'completed'],
        ],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

function openAiTextTurn(string $text): PromiseInterface
{
    return Http::response([
        'id' => 'resp_' . uniqid(),
        'status' => 'completed',
        'model' => 'gpt-5.4',
        'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => $text]]]],
        'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
    ]);
}

class PersistedReasoningReplayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Configure the provider without automatic conversation titles.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);
        $app->make('config')->set([
            'ai.conversations.generate_title' => false,
            'ai.providers.openai.key' => 'test-key',
        ]);
    }

    public function testAStoredReasoningTurnReplaysItsToolCallWithoutTheItemIdItsReasoningNoLongerBacks(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence([
            openAiReasoningToolTurn(),
            openAiTextTurn('The number is 72019'),
            openAiTextTurn('Still 72019'),
        ])]);

        $agent = (new RememberingToolUsingAgent)->forUser((object) ['id' => 1]);

        $agent->prompt('Generate a number', provider: 'openai');

        // The stored turn no longer carries the reasoning item, so the replay must not name the function call item either...
        $agent->prompt('Again', provider: 'openai');

        $replayed = collect(Http::recorded())->last()[0];

        $input = collect(json_decode($replayed->body(), true)['input']);

        $functionCalls = $input->where('type', 'function_call')->values();

        $this->assertCount(1, $functionCalls);
        $this->assertSame('call_1', $functionCalls[0]['call_id']);
        $this->assertSame('FixedNumberGenerator', $functionCalls[0]['name']);
        $this->assertArrayNotHasKey('id', $functionCalls[0]);
        $this->assertEmpty($input->where('type', 'reasoning'));
    }

    public function testALiveReasoningTurnStillNamesItsFunctionCallItemWithinTheSameRun(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence([
            openAiReasoningToolTurn(),
            openAiTextTurn('The number is 72019'),
        ])]);

        config(['ai.providers.openai' => [...config('ai.providers.openai'), 'store' => false]]);

        (new RememberingToolUsingAgent)->forUser((object) ['id' => 1])->prompt('Generate a number', provider: 'openai');

        $followUp = collect(Http::recorded())->last()[0];

        $input = collect(json_decode($followUp->body(), true)['input']);

        $reasoningIndex = $input->search(fn (array $item): bool => ($item['type'] ?? '') === 'reasoning');
        $callIndex = $input->search(fn (array $item): bool => ($item['id'] ?? '') === 'fc_1');

        $this->assertNotFalse($reasoningIndex);
        $this->assertSame($reasoningIndex + 1, $callIndex);
    }
}
