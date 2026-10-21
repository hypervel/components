<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Gemini;

use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\GeminiHelpers;
use Hypervel\Tests\Ai\TestCase;

class ReasoningTest extends TestCase
{
    use GeminiHelpers;

    public function testPromptReadsThoughtStepsOffTheResponse(): void
    {
        Http::fake(['*' => $this->fakeThinkingResponse([
            $this->thoughtStep('Let me think...'),
            $this->modelOutput('Hello'),
        ])]);

        $this->assertSame('Let me think...', (new AssistantAgent)->prompt('Hi', provider: 'gemini')->reasoning);
    }

    public function testPromptSeparatesThoughtRunsInterruptedByAnAnswerWithABlankLine(): void
    {
        Http::fake(['*' => $this->fakeThinkingResponse([
            $this->thoughtStep('First.'),
            $this->modelOutput('Partial answer. '),
            $this->thoughtStep('Second.'),
            $this->modelOutput('Rest of the answer.'),
        ])]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'gemini');

        $this->assertSame("First.\n\nSecond.", $response->reasoning);
        $this->assertSame('Partial answer. Rest of the answer.', $response->text);
    }
}
