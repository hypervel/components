<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features;

use Generator;
use Hypervel\Ai\Contracts\Agent;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Events\AgentFailed;
use Hypervel\Ai\Events\AgentFailedOver;
use Hypervel\Ai\Events\AgentPrompted;
use Hypervel\Ai\Events\AgentStreamAbandoned;
use Hypervel\Ai\Events\AgentStreamed;
use Hypervel\Ai\Events\EmbeddingsFailed;
use Hypervel\Ai\Events\EmbeddingsGenerated;
use Hypervel\Ai\Events\GeneratingEmbeddings;
use Hypervel\Ai\Events\InvokingTool;
use Hypervel\Ai\Events\PromptingAgent;
use Hypervel\Ai\Events\StartingStep;
use Hypervel\Ai\Events\StepCompleted;
use Hypervel\Ai\Events\StepFailed;
use Hypervel\Ai\Events\StreamingAgent;
use Hypervel\Ai\Events\ToolFailed;
use Hypervel\Ai\Events\ToolInvoked;
use Hypervel\Ai\Files\Audio;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\ProviderDocument;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Files\RemoteAudio;
use Hypervel\Ai\Files\RemoteDocument;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\RemoteVideo;
use Hypervel\Ai\Files\S3Document;
use Hypervel\Ai\Files\Video;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Prompts\AgentPrompt;
use Hypervel\Ai\Providers\Tools\ProviderTool;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Responses\TextResponse;
use Hypervel\Ai\Tools\ToolNameResolver;
use Hypervel\Http\UploadedFile;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\JsonSchema\Types\ObjectType;
use Hypervel\Sentry\Features\Ai\AiConversation;
use Hypervel\Sentry\Features\Ai\AiDataSanitizer;
use Hypervel\Sentry\Features\Ai\AiInvocationData;
use Hypervel\Sentry\Features\Ai\AiInvocationMeta;
use Hypervel\Sentry\Features\Ai\AiMessage;
use Hypervel\Sentry\Features\Ai\AiMessagePart;
use Hypervel\Sentry\Features\Ai\AiSpanDataBag;
use Hypervel\Sentry\Features\Ai\AiTracingScope;
use Hypervel\Sentry\Features\Ai\AiTracingState;
use Sentry\DataCollection\DataCollectionPolicy;
use Sentry\SentrySdk;
use Sentry\Tracing\Span;
use Sentry\Tracing\SpanContext;
use Sentry\Tracing\SpanStatus;
use Swoole\Coroutine\CanceledException;
use Throwable;
use WeakReference;

class AiIntegration extends Feature
{
    private const string FEATURE_KEY = 'gen_ai';

    private const string FEATURE_KEY_INVOKE_AGENT = 'gen_ai_invoke_agent';

    private const string FEATURE_KEY_CHAT = 'gen_ai_chat';

    private const string FEATURE_KEY_EXECUTE_TOOL = 'gen_ai_execute_tool';

    private const string FEATURE_KEY_EMBEDDINGS = 'gen_ai_embeddings';

    /**
     * Determine whether AI tracing is enabled and the optional package is present.
     */
    public function isApplicable(): bool
    {
        return $this->isTracingFeatureEnabled(self::FEATURE_KEY)
            && class_exists(PromptingAgent::class);
    }

    /**
     * Register only the enabled AI listener families.
     */
    public function onBoot(): void
    {
        $listeners = [];

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY_INVOKE_AGENT)) {
            $listeners += [
                PromptingAgent::class => 'handlePromptingAgentForTracing',
                StreamingAgent::class => 'handlePromptingAgentForTracing',
                AgentPrompted::class => 'handleAgentPromptedForTracing',
                AgentStreamed::class => 'handleAgentPromptedForTracing',
                StartingStep::class => 'handleStartingStepForTracing',
            ];

            if ($this->isTracingFeatureEnabled(self::FEATURE_KEY_CHAT)) {
                $listeners += [
                    StepCompleted::class => 'handleStepCompletedForTracing',
                    StepFailed::class => 'handleStepFailedForTracing',
                ];
            }
        }

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY_EXECUTE_TOOL)) {
            $listeners += [
                InvokingTool::class => 'handleInvokingToolForTracing',
                ToolInvoked::class => 'handleToolInvokedForTracing',
                ToolFailed::class => 'handleToolFailedForTracing',
            ];
        }

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY_INVOKE_AGENT)
            || $this->isTracingFeatureEnabled(self::FEATURE_KEY_EXECUTE_TOOL)) {
            $listeners += [
                AgentFailed::class => 'handleAgentFailedForTracing',
                AgentFailedOver::class => 'handleAgentFailedOverForTracing',
                AgentStreamAbandoned::class => 'handleAgentStreamAbandonedForTracing',
            ];
        }

        if ($this->isTracingFeatureEnabled(self::FEATURE_KEY_EMBEDDINGS)) {
            $listeners += [
                GeneratingEmbeddings::class => 'handleGeneratingEmbeddingsForTracing',
                EmbeddingsGenerated::class => 'handleEmbeddingsGeneratedForTracing',
                EmbeddingsFailed::class => 'handleEmbeddingsFailedForTracing',
            ];
        }

        // Step events replace upstream's HttpRequestIntegration and URL matching;
        // the existing Guzzle aspect supplies HTTP children without global AI HTTP listeners.
        $events = $this->container->make('events');

        foreach ($listeners as $event => $method) {
            $events->listen($event, function (object $event) use ($method): void {
                $this->container->make(self::class)->{$method}($event);
            });
        }
    }

    /**
     * Start an invocation under the current sampled span.
     */
    public function handlePromptingAgentForTracing(PromptingAgent $event): void
    {
        $sentryScope = AiTracingScope::sentryScope();
        $parentSpan = $sentryScope->getSpan();
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $agentName = class_basename($event->prompt->agent);
        $model = $event->prompt->model;
        $isStreaming = $event instanceof StreamingAgent;

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'invoke_agent',
            'gen_ai.agent.name' => $agentName,
            'gen_ai.request.model' => $model,
        ]);

        if ($isStreaming) {
            $data->set('gen_ai.response.streaming', true);
        }

        $data->set('gen_ai.provider.name', $event->prompt->provider->name());

        if ($this->shouldCollectGenAiInputs()) {
            $inputMessages = $this->buildUserInputMessageFromParts(
                $event->prompt->prompt,
                $this->resolveAttachments($event->prompt)
            );
            $data->set('gen_ai.input.messages', $this->truncateMessages($inputMessages));
        }

        $agentSpan = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.invoke_agent')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.hypervel')
                ->setDescription('invoke_agent ' . $model)
        );

        $scope = AiTracingScope::getOrCreate();
        $previousAttempt = $scope->state->invocations->get($event->invocationId);
        $previousConversation = $scope->conversation;
        $ownsConversation = $this->startConversation(
            $event->prompt->agent,
            $agentSpan,
            $scope,
            $previousAttempt?->ownsConversation ? $previousAttempt->conversation : null,
        );
        $scope->conversation?->attach($agentSpan);

        $invocation = new AiInvocationData(
            $agentSpan,
            WeakReference::create($scope),
            new AiInvocationMeta($agentName),
            $isStreaming,
            $scope->conversation,
            $ownsConversation,
            $event->prompt->parentInvocationId !== null,
            $previousConversation,
        );

        if ($previousAttempt !== null) {
            $invocation->spans = [$previousAttempt->span, ...$previousAttempt->spans];
        }

        $scope->state->invocations->set($event->invocationId, $invocation);
        $scope->push($agentSpan, $sentryScope);
    }

    /**
     * Complete an invocation with response metadata.
     */
    public function handleAgentPromptedForTracing(AgentPrompted $event): void
    {
        $invocation = AiTracingScope::current()?->state->invocations->pull($event->invocationId);
        if ($invocation === null) {
            return;
        }

        $agentSpan = $invocation->span;
        $this->setConversationId($invocation, $event->response->conversationId);

        $data = new AiSpanDataBag($agentSpan->getData());
        $data->set('gen_ai.response.model', $event->response->meta->model);
        $data->setIfNotExists('gen_ai.provider.name', $event->response->meta->provider);
        $data->setTokenUsage($event->response->usage);

        if ($this->shouldCollectGenAiOutputs()) {
            $outputMessages = $this->buildOutputMessages($event->response);
            $data->set('gen_ai.output.messages', $this->truncateMessages(array_reverse($outputMessages)));
        }

        $agentSpan->setData($data->toArray());
        $invocation->finish(SpanStatus::ok());
    }

    /**
     * Close a failed invocation after failed-turn persistence.
     */
    public function handleAgentFailedForTracing(AgentFailed $event): void
    {
        $this->finishFailedInvocation($event->invocationId, $event->prompt->agent, AiTracingState::failureStatus($event->exception));
    }

    /**
     * Close the failed attempt before another provider reuses the invocation ID.
     */
    public function handleAgentFailedOverForTracing(AgentFailedOver $event): void
    {
        $this->finishFailedInvocation($event->invocationId, $event->agent, AiTracingState::failureStatus($event->exception), willRetry: true);
    }

    /**
     * Close an invocation whose consumer stopped before completion.
     */
    public function handleAgentStreamAbandonedForTracing(AgentStreamAbandoned $event): void
    {
        $this->finishFailedInvocation($event->invocationId, $event->prompt->agent, SpanStatus::internalError());
    }

    /**
     * Close the invocation and any tool generator abandoned with it.
     */
    private function finishFailedInvocation(string $invocationId, Agent $agent, SpanStatus $status, bool $willRetry = false): void
    {
        $state = AiTracingScope::current()?->state;

        if ($state === null) {
            return;
        }

        foreach ($state->toolInvocations->newestFirst() as $toolId => $tool) {
            if ($tool['invocationId'] === $invocationId) {
                $state->toolInvocations->pull($toolId);
                AiTracingScope::finishSpan($tool['span'], $tool['owner']->get(), $status);
            }
        }

        $invocation = $willRetry
            ? $state->invocations->get($invocationId)
            : $state->invocations->pull($invocationId);

        if ($invocation !== null && $invocation->span->getEndTimestamp() === null) {
            // Failed-turn persistence may have assigned the new conversation's ID.
            $this->setConversationId($invocation, $this->resolveConversationId($agent));

            $invocation->finish($status);
        }
    }

    /**
     * Capture resolved metadata and start the exact model step.
     */
    public function handleStartingStepForTracing(StartingStep $event): void
    {
        $scope = AiTracingScope::current();
        $invocation = $scope?->state->invocations->get($event->invocationId);

        if ($invocation === null) {
            return;
        }

        $invocation->finishActiveChatSpan();
        $meta = $invocation->meta;

        if (! $invocation->hasStepMetadata) {
            $invocation->hasStepMetadata = true;
            $meta->toolDefinitions = $this->shouldCollectToolDefinitions()
                ? $this->resolveToolDefinitions($event->tools)
                : null;

            $data = new AiSpanDataBag($invocation->span->getData());
            $data->set('gen_ai.tool.definitions', $meta->toolDefinitions);
            $data->set('gen_ai.request.temperature', $event->options?->temperature);
            $data->set('gen_ai.request.max_tokens', $event->options?->maxTokens);

            if ($this->shouldCollectGenAiInputs()) {
                $data->set('gen_ai.system_instructions', AiDataSanitizer::truncateString($event->instructions));
            }

            $invocation->span->setData($data->toArray());
        }

        if (! $this->isTracingFeatureEnabled(self::FEATURE_KEY_CHAT)) {
            return;
        }

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'chat',
        ]);

        if ($invocation->isStreaming) {
            $data->set('gen_ai.response.streaming', true);
        }
        $data->set('gen_ai.request.model', $event->model);
        $data->set('gen_ai.agent.name', $meta->agentName);
        $data->set('gen_ai.provider.name', $event->provider->name());
        $data->set('gen_ai.tool.definitions', $meta->toolDefinitions);
        $data->set('gen_ai.request.temperature', $event->options?->temperature);
        $data->set('gen_ai.request.max_tokens', $event->options?->maxTokens);

        if ($this->shouldCollectGenAiInputs()) {
            $data->set('gen_ai.input.messages', $this->truncateMessages($this->buildChatInputMessages($event->messages)));
            $data->set('gen_ai.system_instructions', AiDataSanitizer::truncateString($event->instructions));
        }

        $chatSpan = $invocation->span->startChild(
            SpanContext::make()
                ->setOp('gen_ai.chat')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.hypervel')
                ->setDescription('chat ' . $event->model)
        );

        $invocation->activeChatSpan = $chatSpan;
        $invocation->activeStep = $event->stepNumber;
        $invocation->spans[] = $chatSpan;
        $invocation->conversation?->attach($chatSpan);

        $scope->push($chatSpan, AiTracingScope::sentryScope());
    }

    /**
     * Record the model response and finish its step span.
     */
    public function handleStepCompletedForTracing(StepCompleted $event): void
    {
        $invocation = AiTracingScope::current()?->state->invocations->get($event->invocationId);

        if ($invocation === null || $invocation->activeStep !== $event->stepNumber || $invocation->activeChatSpan === null) {
            return;
        }

        $data = new AiSpanDataBag($invocation->activeChatSpan->getData());
        $data->set('gen_ai.response.model', $event->response->meta->model);
        $data->set('gen_ai.response.finish_reasons', $event->response->finishReason->value);
        $data->setTokenUsage($event->response->usage);

        if ($this->shouldCollectGenAiOutputs()) {
            $data->set('gen_ai.output.messages', $this->truncateMessages(array_reverse($this->buildOutputMessages($event->response))));
        }

        $invocation->activeChatSpan->setData($data->toArray());
        $invocation->finishActiveChatSpan();
    }

    /**
     * Finish a failed model step, including failures during stream consumption.
     */
    public function handleStepFailedForTracing(StepFailed $event): void
    {
        $invocation = AiTracingScope::current()?->state->invocations->get($event->invocationId);

        if ($invocation !== null && $invocation->activeStep === $event->stepNumber) {
            $invocation->finishActiveChatSpan(AiTracingState::failureStatus($event->exception));
        }
    }

    /**
     * Start a tool span without resolving its schema again.
     */
    public function handleInvokingToolForTracing(InvokingTool $event): void
    {
        $sentryScope = AiTracingScope::sentryScope();
        $parentSpan = $sentryScope->getSpan();
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $toolName = ToolNameResolver::resolve($event->tool);
        $agentName = class_basename($event->agent);

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'execute_tool',
            'gen_ai.tool.name' => $toolName,
            'gen_ai.tool.type' => 'function',
            'gen_ai.agent.name' => $agentName,
        ]);
        $data->set('gen_ai.tool.description', (string) $event->tool->description());

        if ($this->shouldCollectGenAiInputs() && ! empty($event->arguments)) {
            $data->set('gen_ai.tool.call.arguments', AiDataSanitizer::encode($event->arguments));
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.execute_tool')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.hypervel')
                ->setDescription('execute_tool ' . $toolName)
        );

        $scope = AiTracingScope::getOrCreate();
        $scope->state->toolInvocations->set($event->toolInvocationId, [
            'span' => $span,
            'owner' => WeakReference::create($scope),
            'invocationId' => $event->invocationId,
        ]);

        $invocation = $scope->state->invocations->get($event->invocationId);

        if ($invocation !== null) {
            $invocation->spans[] = $span;
        }

        ($invocation !== null ? $invocation->conversation : $scope->conversation)?->attach($span);

        $scope->push($span, $sentryScope);
    }

    /**
     * Record a tool result and restore its caller's span.
     */
    public function handleToolInvokedForTracing(ToolInvoked $event): void
    {
        $invocation = AiTracingScope::current()?->state->toolInvocations->pull($event->toolInvocationId);
        if ($invocation === null) {
            return;
        }

        $span = $invocation['span'];
        $data = new AiSpanDataBag($span->getData());

        if ($this->shouldCollectGenAiOutputs()) {
            $data->set('gen_ai.tool.call.result', is_string($event->result)
                ? AiDataSanitizer::truncateString(AiDataSanitizer::redactBinaryInString($event->result))
                : AiDataSanitizer::encodeIfNotString($event->result));
        }

        $span->setData($data->toArray());
        AiTracingScope::finishSpan($span, $invocation['owner']->get(), SpanStatus::ok());
    }

    /**
     * Close a tool span when execution throws or is canceled.
     */
    public function handleToolFailedForTracing(ToolFailed $event): void
    {
        $invocation = AiTracingScope::current()?->state->toolInvocations->pull($event->toolInvocationId);

        if ($invocation !== null) {
            AiTracingScope::finishSpan($invocation['span'], $invocation['owner']->get(), AiTracingState::failureStatus($event->exception));
        }
    }

    /**
     * Start an embeddings span under the current sampled span.
     */
    public function handleGeneratingEmbeddingsForTracing(GeneratingEmbeddings $event): void
    {
        $sentryScope = AiTracingScope::sentryScope();
        $parentSpan = $sentryScope->getSpan();
        if ($parentSpan === null || ! $parentSpan->getSampled()) {
            return;
        }

        $data = new AiSpanDataBag([
            'gen_ai.operation.name' => 'embeddings',
            'gen_ai.request.model' => $event->model,
            'gen_ai.provider.name' => $event->provider->name(),
        ]);

        if ($this->shouldCollectGenAiInputs()) {
            $data->set('gen_ai.embeddings.input', $this->truncateEmbeddingInputs($event->prompt->inputs));
        }

        $span = $parentSpan->startChild(
            SpanContext::make()
                ->setOp('gen_ai.embeddings')
                ->setData($data->toArray())
                ->setOrigin('auto.ai.hypervel')
                ->setDescription('embeddings ' . $event->model)
        );

        $scope = AiTracingScope::getOrCreate();
        $scope->state->embeddingsInvocations->set($event->invocationId, [
            'span' => $span,
            'owner' => WeakReference::create($scope),
        ]);

        $scope->conversation?->attach($span);

        $scope->push($span, $sentryScope);
    }

    /**
     * Complete embeddings with the returned model and input-token count.
     */
    public function handleEmbeddingsGeneratedForTracing(EmbeddingsGenerated $event): void
    {
        $invocation = AiTracingScope::current()?->state->embeddingsInvocations->pull($event->invocationId);

        if ($invocation === null) {
            return;
        }

        $span = $invocation['span'];
        $data = new AiSpanDataBag($span->getData());
        $data->set('gen_ai.response.model', $event->response->meta->model);
        $data->setIfNotExists('gen_ai.provider.name', $event->response->meta->provider);

        $data->setNonZero('gen_ai.usage.input_tokens', $event->response->usage->inputTokens);

        $span->setData($data->toArray());
        AiTracingScope::finishSpan($span, $invocation['owner']->get(), SpanStatus::ok());
    }

    /**
     * Close a failed embeddings attempt by its exact invocation ID.
     */
    public function handleEmbeddingsFailedForTracing(EmbeddingsFailed $event): void
    {
        $invocation = AiTracingScope::current()?->state->embeddingsInvocations->pull($event->invocationId);

        if ($invocation !== null) {
            AiTracingScope::finishSpan($invocation['span'], $invocation['owner']->get(), AiTracingState::failureStatus($event->exception));
        }
    }

    /**
     * Method used so that other integrations that produce gen_ai spans can submit their spans
     * here which will be populated with the conversation ID once it becomes available.
     */
    public function attachSpanToConversation(Span $span): void
    {
        AiTracingScope::current()?->conversation?->attach($span);
    }

    /**
     * Set the conversation of the gen_ai spans started from now on in the current trace, replacing the current one.
     * Passing null forgets the current conversation.
     */
    public function setCurrentConversationId(?string $conversationId): void
    {
        if ($conversationId === null) {
            $scope = AiTracingScope::current();

            if ($scope !== null) {
                $scope->conversation = null;
            }

            return;
        }

        $span = AiTracingScope::sentryScope()->getSpan();

        // Without a span there is no trace whose gen_ai spans could get the conversation ID
        if ($span !== null) {
            AiTracingScope::getOrCreate()->conversation = new AiConversation((string) $span->getTraceId(), $conversationId);
        }
    }

    /**
     * Start the conversation of an agent that remembers it, replacing the current one. A new conversation gets its ID once the first turn ends.
     */
    private function startConversation(Agent $agent, Span $agentSpan, AiTracingScope $scope, ?AiConversation $failedOverConversation): bool
    {
        if ($scope->conversation !== null && $scope->conversation->traceId !== (string) $agentSpan->getTraceId()) {
            $scope->conversation = null;
        }

        if (! method_exists($agent, 'hasConversationParticipant')) {
            return false;
        }

        $conversationId = $this->resolveConversationId($agent);

        if ($conversationId !== null || $agent->hasConversationParticipant()) {
            // Failed-over attempts of a new conversation receive its eventual stored ID too.
            $scope->conversation = $conversationId === null && $failedOverConversation !== null && $failedOverConversation->conversationId === null
                ? $failedOverConversation
                : new AiConversation((string) $agentSpan->getTraceId(), $conversationId);

            return true;
        }

        return false;
    }

    /**
     * Resolve the invocation's captured conversation association.
     */
    private function setConversationId(AiInvocationData $invocation, ?string $conversationId): void
    {
        if ($conversationId === null) {
            return;
        }

        $invocation->setConversationIdOnSpans($conversationId);
        $conversation = $invocation->conversation;

        // A response may resolve its own new conversation, but must not rename an inherited one.
        if ($invocation->ownsConversation && $conversation !== null && $conversation->conversationId === null) {
            $conversation->resolve($conversationId);
        } elseif (! $invocation->isNested && $conversation?->conversationId !== $conversationId) {
            $conversation = new AiConversation((string) $invocation->span->getTraceId(), $conversationId);
        }

        // Only top-level calls select the conversation used by subsequent work in this scope.
        if (! $invocation->isNested && ($scope = $invocation->owner->get()) !== null) {
            $scope->conversation = $conversation;
        }
    }

    /**
     * Resolve the conversation ID from a remembering agent.
     */
    private function resolveConversationId(Agent $agent): ?string
    {
        return method_exists($agent, 'currentConversation') ? $agent->currentConversation() : null;
    }

    /**
     * Describe attachments without reading their content or discovering their MIME types.
     *
     * @return AiMessagePart[]
     */
    private function resolveAttachments(AgentPrompt $prompt): array
    {
        $parts = [];
        foreach ($prompt->attachments as $attachment) {
            $parts[] = $this->transformAttachment($attachment);
        }

        return $parts;
    }

    /**
     * Convert declared file metadata to a message part without file I/O.
     */
    private function transformAttachment(File|UploadedFile $attachment): AiMessagePart
    {
        if ($attachment instanceof UploadedFile) {
            $name = $attachment->getClientOriginalName();
            $mimeType = $attachment->getClientMimeType();
            $modality = match (strstr($mimeType, '/', true)) {
                'image' => 'image',
                'audio' => 'audio',
                'video' => 'video',
                default => 'document',
            };
        } else {
            $name = $attachment->name;
            $mimeType = $attachment->mime;
            $modality = match (true) {
                $attachment instanceof Image => 'image',
                $attachment instanceof Document => 'document',
                $attachment instanceof Audio => 'audio',
                $attachment instanceof Video => 'video',
                default => 'file',
            };
        }

        if ($attachment instanceof RemoteImage || $attachment instanceof RemoteDocument
            || $attachment instanceof RemoteAudio || $attachment instanceof RemoteVideo || $attachment instanceof S3Document) {
            $type = 'uri';
            $content = $attachment->url;
        } elseif ($attachment instanceof ProviderImage || $attachment instanceof ProviderDocument) {
            $type = 'file_id';
            $content = $attachment->id;
        } else {
            $type = 'blob';
            $content = AiDataSanitizer::BLOB_SUBSTITUTE;
        }

        return (new AiMessagePart($type))
            ->setModality($modality)
            ->setContent($content)
            ->setName($name)
            ->setMimeType($mimeType);
    }

    /**
     * Build the initial user message from its prompt and attachment metadata.
     *
     * @param AiMessagePart[] $attachmentParts
     * @return AiMessage[]
     */
    private function buildUserInputMessageFromParts(string $promptText, array $attachmentParts): array
    {
        $parts = $promptText !== ''
            ? [(new AiMessagePart('text'))->setContent($promptText)]
            : [];

        $parts = array_merge($parts, $attachmentParts);

        if (empty($parts)) {
            return [];
        }

        return [new AiMessage('user', $parts)];
    }

    /**
     * Yield the model step's messages newest first, formatting only as they are consumed.
     *
     * @param Message[] $messages
     * @return Generator<int, AiMessage>
     */
    private function buildChatInputMessages(array $messages): Generator
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof ToolResultMessage) {
                foreach ($message->toolResults->reverse() as $result) {
                    $part = $this->toolResultPart($result);

                    if ($part !== null) {
                        yield new AiMessage('tool', [$part]);
                    }
                }

                continue;
            }

            $parts = $message->content !== null && $message->content !== ''
                ? [(new AiMessagePart('text'))->setContent($message->content)]
                : [];

            if ($message instanceof UserMessage) {
                foreach ($message->attachments as $attachment) {
                    $parts[] = $this->transformAttachment($attachment);
                }
            } elseif ($message instanceof AssistantMessage) {
                foreach ($message->toolCalls as $call) {
                    $parts[] = $this->toolCallPart($call);
                }
            }

            if ($parts !== []) {
                yield new AiMessage($message->role->value, $parts);
            }
        }
    }

    /**
     * Build output messages from a response or completed step.
     *
     * @return AiMessage[]
     */
    private function buildOutputMessages(TextResponse|StepResponse $source): array
    {
        $messages = [];
        $parts = [];
        if ($source->text !== '') {
            $parts[] = (new AiMessagePart('text'))->setContent($source->text);
        }

        foreach ($source->toolCalls as $toolCall) {
            if (is_a($toolCall, ToolCall::class)) {
                $parts[] = $this->toolCallPart($toolCall);
            }
        }

        if (! empty($parts)) {
            $messages[] = new AiMessage('assistant', $parts);
        }

        foreach ($source instanceof StepResponse ? [] : $source->toolResults as $toolResult) {
            if (! is_a($toolResult, ToolResult::class)) {
                continue;
            }
            $part = $this->toolResultPart($toolResult);
            if ($part === null) {
                continue;
            }

            $messages[] = new AiMessage('tool', [$part]);
        }

        return $messages;
    }

    /**
     * Format tool arguments without cutting their encoded JSON.
     */
    private function toolCallPart(ToolCall $call): AiMessagePart
    {
        return (new AiMessagePart('tool_call'))
            ->setId($call->id)
            ->setName($call->name)
            ->setArguments(AiDataSanitizer::encode($call->arguments, AiDataSanitizer::MAX_SINGLE_MESSAGE_CONTENT_BYTES));
    }

    /**
     * Bound tool output before placing either text or encoded JSON in a message.
     */
    private function toolResultPart(ToolResult $result): ?AiMessagePart
    {
        $content = is_string($result->result)
            ? AiDataSanitizer::truncateContentString(AiDataSanitizer::redactBinaryInString($result->result))
            : ($result->result === null ? null : AiDataSanitizer::encode($result->result, AiDataSanitizer::MAX_SINGLE_MESSAGE_CONTENT_BYTES));

        return $content === null ? null : (new AiMessagePart('tool_call_response'))
            ->setContent($content)
            ->setId($result->id)
            ->setName($result->name);
    }

    /**
     * The legacy options always collected the tool definitions, the data collection options treat them as inputs.
     */
    private function shouldCollectToolDefinitions(): bool
    {
        return DataCollectionPolicy::fromHub(SentrySdk::getCurrentHub())->isLegacyMode() || $this->shouldCollectGenAiInputs();
    }

    /**
     * Encode the tools already resolved for the first model step.
     *
     * @param array<ProviderTool|Tool> $tools
     */
    private function resolveToolDefinitions(array $tools): ?string
    {
        $definitions = [];
        foreach ($tools as $tool) {
            if ($tool instanceof Tool) {
                $definitions[] = $this->resolveToolDefinition($tool);
            }
        }
        if (empty($definitions)) {
            return null;
        }

        return AiDataSanitizer::encode($definitions);
    }

    /**
     * Describe one callable tool and its schema.
     *
     * @return array<string, mixed>
     */
    private function resolveToolDefinition(Tool $tool): array
    {
        $definition = [
            'type' => 'function',
            'name' => ToolNameResolver::resolve($tool),
        ];

        $description = (string) $tool->description();
        if ($description !== '') {
            $definition['description'] = $description;
        }

        try {
            $factory = new JsonSchemaTypeFactory;
            $properties = $tool->schema($factory);
            if (! empty($properties)) {
                $objectType = new ObjectType($properties);
                $definition['parameters'] = $objectType->toArray();
            }
        } catch (CanceledException $exception) {
            // User-defined schema hooks may perform cancellable I/O.
            throw $exception;
        } catch (Throwable $exception) {
            // Ignore schema resolution failures.
        }

        return $definition;
    }

    /**
     * Keep the newest complete messages that fit within the payload budget.
     *
     * @param iterable<AiMessage> $newestFirstMessages messages ordered newest first
     */
    private function truncateMessages(iterable $newestFirstMessages): ?string
    {
        $kept = [];
        $totalBytes = 2;

        foreach ($newestFirstMessages as $message) {
            foreach ($message->getParts() as $part) {
                // Tool results are already bounded, and their content may be encoded JSON.
                if ($part->getType() === 'tool_call_response') {
                    continue;
                }

                if ($part->getType() === 'blob') {
                    $part->setContent(AiDataSanitizer::BLOB_SUBSTITUTE);
                } elseif ($part->getContent() !== null) {
                    $part->setContent(AiDataSanitizer::redactBinaryInString($part->getContent()));
                }

                if ($part->getContent() !== null) {
                    $part->setContent(AiDataSanitizer::truncateContentString($part->getContent()));
                }
            }

            $separatorBytes = $kept === [] ? 0 : 1;
            $messageJson = AiDataSanitizer::encode($message, AiDataSanitizer::MAX_MESSAGE_BYTES - $totalBytes - $separatorBytes);
            if ($messageJson === null) {
                if ($kept === []) {
                    return null;
                }

                break;
            }

            $kept[] = $messageJson;
            $totalBytes += strlen($messageJson) + $separatorBytes;
        }

        return '[' . implode(',', array_reverse($kept)) . ']';
    }

    /**
     * Format only the embedding inputs that fit within the byte budget.
     *
     * @param array<int, File|string> $inputs
     */
    private function truncateEmbeddingInputs(array $inputs): ?string
    {
        if (empty($inputs)) {
            return '[]';
        }

        $kept = [];
        $totalBytes = 2;

        foreach ($inputs as $input) {
            $value = is_string($input)
                ? AiDataSanitizer::truncateContentString(AiDataSanitizer::redactBinaryInString($input))
                : $this->transformAttachment($input);
            $inputJson = AiDataSanitizer::encode($value);

            if ($inputJson === null) {
                break;
            }

            $entryBytes = strlen($inputJson) + ($kept === [] ? 0 : 1);
            if ($totalBytes + $entryBytes > AiDataSanitizer::MAX_MESSAGE_BYTES) {
                break;
            }

            $kept[] = $inputJson;
            $totalBytes += $entryBytes;
        }

        return $kept === [] ? null : '[' . implode(',', $kept) . ']';
    }
}
