<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Closure;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Approvals\Decision;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Providers\Tools\ProviderTool;
use Hypervel\Support\Collection;

trait ResumesToolApprovals
{
    /**
     * Get the tool approval to resume with, unless a faked agent has no paused approval to resume.
     *
     * @param array<int, Message> $messages
     * @param array<ProviderTool|Tool> $tools
     * @return null|array<string, Decision>
     */
    protected function resumableApprovalFor(AgentPrompt $prompt, array $messages, array $tools): ?array
    {
        return $this->resumesApproval($prompt, $messages, $tools) ? $prompt->approvalDecisions->all() : null;
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
     * Determine whether the prompt is a resume that runs the approved tools.
     *
     * @param array<int, Message> $messages
     * @param array<ProviderTool|Tool> $tools
     */
    protected function resumesApproval(AgentPrompt $prompt, array $messages, array $tools): bool
    {
        if (! $prompt->hasApprovalDecisions()) {
            return false;
        }

        if (! Ai::hasFakeGatewayFor($prompt->agent::class)) {
            return true;
        }

        // A faked agent resumes like the real gateway once it actually paused for approval, but decisions sent to a fake with nothing paused still just take its next response...
        return $this->textGenerationLoop()->pausedForApproval($messages, $tools);
    }

    /**
     * Get a callback that captures a resume's resolved approval results for its completion event.
     *
     * @param null|array<string, Decision> $approval
     */
    protected function approvalResultRecorderFor(AgentPrompt $prompt, ?array $approval, ?Collection &$resolvedApprovalResults): ?Closure
    {
        if ($approval === null) {
            return null;
        }

        return function (array $toolResults) use (&$resolvedApprovalResults): void {
            $resolvedApprovalResults = collect($toolResults);
        };
    }

    // REMOVED: storeApprovalResultRecorderFor(); RunContext persists each result under the claim before the next tool runs.
}
