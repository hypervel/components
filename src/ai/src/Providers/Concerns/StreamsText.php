<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Generator;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Contracts\RemembersConversations as RemembersConversationsContract;
use Hypervel\Ai\Events\AgentStreamed;
use Hypervel\Ai\Events\StreamingAgent;
use Hypervel\Ai\Events\ToolApprovalRequested;
use Hypervel\Ai\Events\ToolApprovalResolved;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Middleware\RememberConversation;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Responses\StreamedAgentResponse;
use Hypervel\Ai\Streaming\Events\ToolApprovalRequest;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Throwable;

use function Hypervel\Ai\pipeline;

trait StreamsText
{
    use ResumesToolApprovals;

    /**
     * Stream the response from the given agent.
     */
    public function stream(AgentPrompt $prompt): StreamableAgentResponse
    {
        $prompt->setContextRunner($prompt->contextRunner() ?? Ai::captureContext());

        $invocationId = $prompt->invocationId ?? (string) Str::uuid7();

        $resolvedApprovalResults = null;

        try {
            $response = pipeline()
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($invocationId, &$resolvedApprovalResults): StreamableAgentResponse {
                    $agent = $prompt->agent;

                    if ($agent instanceof HasStructuredOutput) {
                        throw new InvalidArgumentException('Streaming structured output is not currently supported.');
                    }

                    $meta = new Meta($this->name(), $prompt->model);

                    $messages = $this->withoutForeignReplayBlocks([
                        ...($prompt->messages ?? []),
                        ...($agent instanceof Conversational ? $agent->messages() : []),
                    ]);

                    if (! $prompt->hasApprovalDecisions()) {
                        $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());
                    }

                    $tools = $this->resolveTools($prompt);
                    $approval = $this->resumableApprovalFor($prompt);
                    $recordApprovalResults = $this->approvalResultRecorderFor($prompt, $resolvedApprovalResults);

                    // Validate eagerly so a mismatch throws before the stream begins, then thread the result into stream() so it isn't re-validated there...
                    $validatedApproval = $approval !== null
                        ? $this->textGenerationLoop()->validateApproval($approval, $messages, $tools)
                        : null;

                    $context = $this->runContextFor($invocationId, $prompt);
                    $streamable = null;

                    // The response owns the "has anything reached the consumer" flag so this failure check and the caller's failover decision can never drift apart...
                    $streamable = new StreamableAgentResponse(
                        $invocationId,
                        function () use ($invocationId, $prompt, $agent, $messages, $tools, $approval, $recordApprovalResults, $validatedApproval, $context, &$streamable): Generator {
                            // Re-iteration needs fresh run state with the dependencies captured by the factory.
                            $prompt->setRunContext($runContext = clone $context);

                            if ($this->events->hasListeners(StreamingAgent::class)) {
                                $this->events->dispatch(new StreamingAgent($invocationId, $prompt));
                            }

                            try {
                                foreach ($this->textGenerationLoop()->stream(
                                    $invocationId,
                                    $this,
                                    $prompt->model,
                                    (string) $agent->instructions(),
                                    $messages,
                                    $tools,
                                    null,
                                    TextGenerationOptions::forAgent($agent),
                                    $prompt->timeout,
                                    $approval,
                                    $recordApprovalResults,
                                    $validatedApproval,
                                    $runContext,
                                ) as $event) {
                                    if ($event instanceof ToolApprovalRequest) {
                                        $this->throwIfNotResumable($prompt);
                                    }

                                    yield $event;
                                }
                            } catch (Throwable $exception) {
                                $this->recordAgentFailure($invocationId, $prompt, $exception, retryable: ! $streamable->hasYielded());

                                throw $exception;
                            }
                        },
                        $meta,
                    );

                    // Surfaced before iteration because the remembering middleware only records it once the stream has drained...
                    if (RememberConversation::appliesTo($agent)) {
                        /** @var Agent&RemembersConversationsContract $agent */
                        if ($agent->currentConversation() !== null) {
                            $streamable->withinConversation(
                                $agent->currentConversation(),
                                $agent->conversationParticipant(),
                            );
                        }
                    }

                    return $streamable;
                });
        } catch (Throwable $exception) {
            $this->recordAgentFailure($invocationId, $prompt, $exception);

            throw $exception;
        }

        return $response->usingContext($prompt->contextRunner())->then(function (StreamedAgentResponse $response) use ($invocationId, $prompt, &$resolvedApprovalResults): void {
            if ($this->events->hasListeners(AgentStreamed::class)) {
                $this->events->dispatch(
                    new AgentStreamed($invocationId, $prompt, $response)
                );
            }

            if ($response->hasPendingApprovals() && $this->events->hasListeners(ToolApprovalRequested::class)) {
                $this->events->dispatch(new ToolApprovalRequested(
                    $invocationId,
                    $prompt->agent,
                    $response->pendingApprovals,
                    $response->conversationId,
                    $response->conversationUser,
                ));
            }

            if ($resolvedApprovalResults !== null && $this->events->hasListeners(ToolApprovalResolved::class)) {
                $this->events->dispatch(new ToolApprovalResolved(
                    $invocationId,
                    $prompt->agent,
                    $resolvedApprovalResults,
                    $response->conversationId,
                    $response->conversationUser,
                ));
            }
        });
    }
}
