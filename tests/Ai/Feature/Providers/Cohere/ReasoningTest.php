<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Cohere;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\CohereHelpers;
use Hypervel\Tests\Ai\TestCase;

class ReasoningTest extends TestCase
{
    use CohereHelpers;

    /**
     * Configure provider credentials.
     */
    protected function defineEnvironment(Application $app): void
    {
        $app->make('config')->set('ai.providers.cohere.key', 'test-key');
    }

    public function testPromptReadsTheThinkingBlocksOffTheResponse(): void
    {
        Http::fake(['*' => $this->fakeTextResponse([
            ['type' => 'thinking', 'thinking' => 'Let me think...'],
            ['type' => 'text', 'text' => 'Hello'],
        ])]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'cohere');

        $this->assertSame('Let me think...', $response->reasoning);
        $this->assertSame('Hello', $response->text);
    }

    public function testPromptSeparatesEachThinkingBlockWithABlankLine(): void
    {
        Http::fake(['*' => $this->fakeTextResponse([
            ['type' => 'thinking', 'thinking' => 'First.'],
            ['type' => 'thinking', 'thinking' => '   '],
            ['type' => 'thinking', 'thinking' => 'Second.'],
            ['type' => 'text', 'text' => 'Hello'],
        ])]);

        $this->assertSame("First.\n\nSecond.", (new AssistantAgent)->prompt('Hi', provider: 'cohere')->reasoning);
    }
}
