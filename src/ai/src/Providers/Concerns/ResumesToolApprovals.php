<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Closure;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Support\Collection;

trait ResumesToolApprovals
{
    /**
     * Get the tool approval to resume with, unless the agent's gateway is faked.
     *
     * @return null|array<string, Decision>
     */
    protected function resumableApprovalFor(AgentPrompt $prompt): ?array
    {
        return $this->resumesAgainstRealGateway($prompt) ? $prompt->approvalDecisions->all() : null;
    }

    /**
     * Replace another provider's raw paused-turn replay state with its generic mapping, since raw blocks are only valid verbatim on the provider that produced them.
     *
     * @param array<int, Message> $messages
     * @return array<int, Message>
     */
    protected function withoutForeignReplayBlocks(array $messages): array
    {
        return array_map(function (Message $message): Message {
            if ($message instanceof AssistantMessage
                && filled($message->replayBlocks)
                && $message->replayBlocksProvider !== null
                && $message->replayBlocksProvider !== $this->name()) {
                return new AssistantMessage($message->content, $message->toolCalls);
            }

            return $message;
        }, $messages);
    }

    /**
     * Determine whether the prompt is a resume that runs tools against the real (non-faked) gateway.
     */
    protected function resumesAgainstRealGateway(AgentPrompt $prompt): bool
    {
        return $prompt->hasApprovalDecisions() && ! Ai::hasFakeGatewayFor($prompt->agent::class);
    }

    /**
     * Get a callback that captures a resume's resolved approval results for its completion event.
     */
    protected function approvalResultRecorderFor(AgentPrompt $prompt, ?Collection &$resolvedApprovalResults): ?Closure
    {
        if (! $this->resumesAgainstRealGateway($prompt)) {
            return null;
        }

        return function (array $toolResults) use (&$resolvedApprovalResults): void {
            $resolvedApprovalResults = collect($toolResults);
        };
    }

    // REMOVED: storeApprovalResultRecorderFor(); RunContext persists each result under the claim before the next tool runs.
}
