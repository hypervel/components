<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Closure;
use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\ClaimsPendingApprovals;
use Hypervel\Ai\Contracts\Conversational;
use Hypervel\Ai\Contracts\ConversationStore;
use Hypervel\Ai\Contracts\HasSkills;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Ai\Contracts\HasTools;
use Hypervel\Ai\Contracts\RemembersConversations;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Events\AgentFailed;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\PromptingAgent;
use Hypervel\Ai\Events\ToolApprovalRequested;
use Hypervel\Ai\Events\ToolApprovalResolved;
use Hypervel\Ai\Exceptions\ApprovalNotResumableException;
use Hypervel\Ai\Gateway\RunContext;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Middleware\RememberConversation;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Providers\Tools\ToolSearch;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\StreamableAgentResponse;
use Hypervel\Ai\Responses\StructuredAgentResponse;
use Hypervel\Ai\Responses\StructuredTextResponse;
use Hypervel\Ai\Responses\TextResponse;
use Hypervel\Ai\Tools\AgentTool;
use Hypervel\Ai\Tools\LoadSkill;
use Hypervel\Ai\Tools\McpServerTool;
use Hypervel\Ai\Tools\McpTool;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Support\Str;
use LogicException;
use Throwable;

use function Hypervel\Ai\pipeline;

trait GeneratesText
{
    use ResumesToolApprovals;

    /**
     * Invoke the given agent.
     */
    public function prompt(AgentPrompt $prompt): AgentResponse
    {
        $invocationId = $prompt->invocationId ?? (string) Str::uuid7();

        $resolvedApprovalResults = null;

        try {
            $response = pipeline()
                ->send($prompt)
                ->through($this->gatherMiddlewareFor($prompt->agent))
                ->then(function (AgentPrompt $prompt) use ($invocationId, &$resolvedApprovalResults): TextResponse {
                    if ($this->events->hasListeners(PromptingAgent::class)) {
                        $this->events->dispatch(new PromptingAgent($invocationId, $prompt));
                    }

                    $agent = $prompt->agent;

                    $messages = $this->withoutForeignReplayBlocks([
                        ...($prompt->messages ?? []),
                        ...($agent instanceof Conversational ? $agent->messages() : []),
                    ]);

                    if (! $prompt->hasApprovalDecisions()) {
                        $messages[] = new UserMessage($prompt->prompt, $prompt->attachments->all());
                    }

                    $schema = $agent instanceof HasStructuredOutput ? $agent->schema(new JsonSchemaTypeFactory) : null;

                    $response = $this->textGenerationLoop()->generate(
                        $this,
                        $prompt->model,
                        (string) $agent->instructions(),
                        $messages,
                        $this->resolveTools($prompt),
                        $schema,
                        TextGenerationOptions::forAgent($agent),
                        $prompt->timeout,
                        $this->resumableApprovalFor($prompt),
                        $this->approvalResultRecorderFor($prompt, $resolvedApprovalResults),
                        $this->runContextFor($invocationId, $prompt),
                    );

                    if ($response->hasPendingApprovals()) {
                        $this->throwIfNotResumable($prompt);
                    }

                    $agentResponse = $response instanceof StructuredTextResponse
                        ? (new StructuredAgentResponse($invocationId, $response->structured, $response->text, $response->usage, $response->meta))
                            ->withMessages($response->messages)
                            ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                            ->withSteps($response->steps)
                            ->withReasoning($response->reasoning)
                            ->withRawResponse($response->raw)
                        : (new AgentResponse($invocationId, $response->text, $response->usage, $response->meta))
                            ->withMessages($response->messages)
                            ->withToolCallsAndResults($response->toolCalls, $response->toolResults)
                            ->withSteps($response->steps)
                            ->withReasoning($response->reasoning)
                            ->withRawResponse($response->raw);

                    $agentResponse->withPendingApprovals($response->pendingApprovals);

                    return $agentResponse;
                });
        } catch (Throwable $exception) {
            $this->recordAgentFailure($invocationId, $prompt, $exception);

            throw $exception;
        }

        if ($this->events->hasListeners(AgentPrompted::class)) {
            $this->events->dispatch(
                new AgentPrompted($invocationId, $prompt, $response)
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

        return $response;
    }

    /**
     * Gather the internal run middleware for the given agent.
     */
    protected function gatherMiddlewareFor(Agent $agent): array
    {
        $middleware = Ai::hasFakeGatewayFor($agent::class) ? [function (AgentPrompt $prompt, Closure $next): AgentResponse|StreamableAgentResponse {
            Ai::recordPrompt($prompt);

            return $next($prompt);
        }] : [];

        if (RememberConversation::appliesTo($agent)) {
            $middleware[] = new RememberConversation(resolve(ConversationStore::class), $this);
        }

        return $middleware;
    }

    /**
     * Resolve the tools for the given prompt, wrapping any agent instances as tools.
     */
    protected function resolveTools(AgentPrompt $prompt): array
    {
        return array_map(
            fn (mixed $tool): mixed => $this->resolveTool($tool),
            $prompt->tools ?? $this->declaredTools($prompt->agent),
        );
    }

    /**
     * Get the tools the agent declares, including the tool that loads its skills.
     */
    protected function declaredTools(Agent $agent): array
    {
        $tools = $agent instanceof HasTools ? [...$agent->tools()] : [];

        return $agent instanceof HasSkills ? LoadSkill::mergeInto($tools, $agent) : $tools;
    }

    /**
     * Resolve a tool returned by the agent into a native tool instance when needed.
     */
    protected function resolveTool(mixed $tool): mixed
    {
        return match (true) {
            $tool instanceof Agent => new AgentTool($tool),
            $tool instanceof Tool => $tool,
            $tool instanceof ToolSearch => $tool->withTools(
                array_map(fn (mixed $nested): mixed => $this->resolveTool($nested), $tool->tools),
            ),
            McpTool::supports($tool) => new McpTool($tool),
            McpServerTool::supports($tool) => new McpServerTool($tool),
            default => $tool,
        };
    }

    /**
     * Build the context that identifies this run and reports its step and tool events.
     */
    protected function runContextFor(string $invocationId, AgentPrompt $prompt): RunContext
    {
        $store = null;
        $conversationId = null;

        if ($this->resumesAgainstRealGateway($prompt) && RememberConversation::appliesTo($prompt->agent)) {
            /** @var Agent&RemembersConversations $agent */
            $agent = $prompt->agent;
            $conversationId = $agent->currentConversation();

            if ($conversationId !== null) {
                $store = resolve(ConversationStore::class);

                if (! $store instanceof ClaimsPendingApprovals) {
                    throw new LogicException('Conversation store [' . $store::class . '] must implement [' . ClaimsPendingApprovals::class . '] to resume stored tool approvals.');
                }
            }
        }

        return tap(
            new RunContext($invocationId, $prompt->agent, $this, $prompt->model, $this->events, $prompt->contextRunner(), $store, $conversationId),
            fn (RunContext $context) => $prompt->setRunContext($context),
        );
    }

    /**
     * Dispatch the terminal failure event for a run, unless the caller may still retry it against another provider.
     */
    protected function recordAgentFailure(string $invocationId, AgentPrompt $prompt, Throwable $exception, bool $retryable = true): void
    {
        // A failoverable exception is only terminal once the caller has run out of providers to try...
        if ($retryable && $prompt->willRetry($exception)) {
            return;
        }

        if ($this->events->hasListeners(AgentFailed::class)) {
            $this->events->dispatch(
                new AgentFailed($invocationId, $prompt, $exception)
            );
        }
    }

    /**
     * Throw when a pause has surfaced on a prompt that cannot be resumed from persisted or replayed history.
     */
    protected function throwIfNotResumable(AgentPrompt $prompt): void
    {
        // An ad-hoc history replays from the client, even when the first turn carried no messages...
        if (! $prompt->agent instanceof Conversational && $prompt->messages === null) {
            throw ApprovalNotResumableException::make();
        }
    }
}
