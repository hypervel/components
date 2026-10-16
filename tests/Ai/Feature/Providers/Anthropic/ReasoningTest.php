<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Anthropic;

use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\AnthropicHelpers;
use Hypervel\Tests\Ai\TestCase;

class ReasoningTest extends TestCase
{
    use AnthropicHelpers;

    public function testPromptReadsThinkingBlocksOffTheResponse(): void
    {
        Http::fake(['*' => $this->fakeThinkingResponse([
            ['type' => 'thinking', 'thinking' => 'Let me think...', 'signature' => 'sig-1'],
            ['type' => 'text', 'text' => 'Hello'],
        ])]);

        $this->assertSame('Let me think...', (new AssistantAgent)->prompt('Hi', provider: 'anthropic')->reasoning);
    }

    public function testPromptSeparatesEachThinkingBlockWithABlankLine(): void
    {
        Http::fake(['*' => $this->fakeThinkingResponse([
            ['type' => 'thinking', 'thinking' => 'First.', 'signature' => 'sig-1'],
            ['type' => 'thinking', 'thinking' => '   ', 'signature' => 'sig-2'],
            ['type' => 'thinking', 'thinking' => 'Second.', 'signature' => 'sig-3'],
            ['type' => 'text', 'text' => 'Hello'],
        ])]);

        $this->assertSame("First.\n\nSecond.", (new AssistantAgent)->prompt('Hi', provider: 'anthropic')->reasoning);
    }

    public function testRedactedThinkingContributesNoReasoningText(): void
    {
        Http::fake(['*' => $this->fakeThinkingResponse([
            ['type' => 'redacted_thinking', 'data' => 'encrypted-blob'],
            ['type' => 'text', 'text' => 'Hello'],
        ])]);

        $this->assertSame('', (new AssistantAgent)->prompt('Hi', provider: 'anthropic')->reasoning);
    }
}
