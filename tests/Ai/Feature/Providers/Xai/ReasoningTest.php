<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature\Providers\Xai;

use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;
use Hypervel\Tests\Ai\Fixtures\XaiHelpers;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ReasoningTest extends TestCase
{
    use XaiHelpers;

    #[DataProvider('reasoningItems')]
    public function testPromptReadsReasoningOffTheResponse(array $item, string $expected): void
    {
        Http::fake(['*' => $this->fakeReasonedTextResponse([$item])]);

        $this->assertSame($expected, (new AssistantAgent)->prompt('Hi', provider: 'xai')->reasoning);
    }

    /**
     * Provide reasoning response shapes.
     */
    public static function reasoningItems(): array
    {
        return [
            'reasoning summary' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [
                    ['type' => 'summary_text', 'text' => 'Let me '],
                    ['type' => 'summary_text', 'text' => 'think...'],
                ]],
                'Let me think...',
            ],
            'reasoning text' => [
                ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [], 'content' => [
                    ['type' => 'reasoning_text', 'text' => 'Raw thoughts.'],
                ]],
                'Raw thoughts.',
            ],
        ];
    }

    #[DataProvider('reasoningPositions')]
    public function testPromptReadsTheAnswerAndFinishReasonWhicheverSideOfTheMessageTheReasoningSitsOn(bool $reasoningFirst): void
    {
        Http::fake(['*' => $this->fakeReasonedTextResponse([
            ['type' => 'reasoning', 'id' => 'rs_1', 'status' => 'completed', 'summary' => [
                ['type' => 'summary_text', 'text' => 'Let me think...'],
            ]],
        ], 'The answer is 303.', $reasoningFirst)]);

        $response = (new AssistantAgent)->prompt('Hi', provider: 'xai');

        $this->assertSame('The answer is 303.', $response->text);
        $this->assertSame('Let me think...', $response->reasoning);
        $this->assertSame(FinishReason::Stop, $response->steps->last()->finishReason);
    }

    /**
     * Provide reasoning positions relative to the message.
     */
    public static function reasoningPositions(): array
    {
        return ['reasoning last' => [false], 'reasoning first' => [true]];
    }

    public function testASummaryAndTheRawReasoningOfOneItemStaySeparateBlocks(): void
    {
        Http::fake(['*' => $this->fakeReasonedTextResponse([
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [
                ['type' => 'summary_text', 'text' => 'I weighed the options.'],
            ], 'content' => [
                ['type' => 'reasoning_text', 'text' => 'Raw thoughts.'],
            ]],
        ])]);

        $this->assertSame("I weighed the options.\n\nRaw thoughts.", (new AssistantAgent)->prompt('Hi', provider: 'xai')->reasoning);
    }

    public function testPromptSeparatesEachReasoningBlockWithABlankLine(): void
    {
        Http::fake(['*' => $this->fakeReasonedTextResponse([
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'First.']]],
            ['type' => 'reasoning', 'id' => 'rs_2', 'summary' => [['type' => 'summary_text', 'text' => '   ']]],
            ['type' => 'reasoning', 'id' => 'rs_3', 'summary' => [['type' => 'summary_text', 'text' => 'Second.']]],
        ])]);

        $this->assertSame("First.\n\nSecond.", (new AssistantAgent)->prompt('Hi', provider: 'xai')->reasoning);
    }
}
