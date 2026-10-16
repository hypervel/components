<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures\Agents;

use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Promptable;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;

class HistoricalToolCallWithEmptyReasoningAgent implements Agent, Conversational
{
    use Promptable;

    /**
     * Get the agent's instructions.
     */
    public function instructions(): string
    {
        return 'You are a helpful assistant.';
    }

    /**
     * Get the agent's provider.
     */
    public function provider(): string
    {
        return 'deepseek';
    }

    /**
     * Get the conversation's messages.
     */
    public function messages(): iterable
    {
        return [
            new UserMessage('check stock'),
            new AssistantMessage(
                'Found the products.',
                collect([
                    new ToolCall('call_empty', 'SearchProducts', ['keyword' => 'lampu'], 'call_empty'),
                ]),
                [['type' => 'reasoning', 'reasoning_content' => '']]
            ),
            new ToolResultMessage(collect([
                new ToolResult('call_empty', 'SearchProducts', ['keyword' => 'lampu'], '[{"name":"Lampu LED"}]', 'call_empty'),
            ])),
            new UserMessage('tell me more'),
        ];
    }
}
