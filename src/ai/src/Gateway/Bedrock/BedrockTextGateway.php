<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Bedrock;

use Aws\BedrockRuntime\BedrockRuntimeClient;
use Aws\Exception\EventStreamDataException;
use Aws\Result;
use Generator;
use Hypervel\Ai\Attributes\CacheInstructions;
use Hypervel\Ai\Attributes\CacheToolDefinitions;
use Hypervel\Ai\Concerns\JoinsReasoning;
use Hypervel\Ai\Contracts\Gateway\EmbeddingGateway;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\EmbeddingProvider;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Exceptions\AiException;
use Hypervel\Ai\Gateway\Bedrock\Concerns\CreatesBedrockClient;
use Hypervel\Ai\Gateway\Bedrock\Concerns\MapsAttachments;
use Hypervel\Ai\Gateway\Cohere\Concerns\ParsesEmbeddings;
use Hypervel\Ai\Gateway\Concerns\DecodesStructuredOutput;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\AssistantMessage;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Messages\MessageRole;
use Hypervel\Ai\Messages\ToolResultMessage;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\ObjectSchema;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\Data\ToolResult;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Ai\Responses\EmbeddingsResponse;
use Hypervel\Ai\Streaming\Events\Error;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\Ai\Tools\ToolNameResolver;
use Hypervel\JsonSchema\JsonSchemaTypeFactory;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use InvalidArgumentException;
use Throwable;

class BedrockTextGateway implements EmbeddingGateway, StepTextGateway
{
    use CreatesBedrockClient;
    use DecodesStructuredOutput;
    use HandlesFailoverErrors;
    use JoinsReasoning;
    use MapsAttachments;
    use ParsesEmbeddings;

    protected const string STRUCTURED_OUTPUT_TOOL = 'structured_output';

    // Substring match on the model ID, so application inference profile ARNs are not detected.
    protected const array MODELS_REJECTING_FORCED_TOOL_CHOICE = [
        'claude-sonnet-5-5',
        'claude-opus-5-5',
        'claude-fable-5-1',
        'claude-mythos-5-1',
    ];

    /**
     * Create a Bedrock text gateway.
     */
    public function __construct()
    {
    }

    /**
     * Generate text for a single Converse step.
     */
    public function generateTextStep(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): StepResponse {
        /** @var Provider&TextProvider $provider */
        $client = $this->createBedrockClient($provider, $timeout);

        $parameters = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn (): Result => $client->converse($parameters),
            );

            $result = $response->toArray();
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        return $this->parseTextResponse($result, $provider, $model, filled($schema));
    }

    /**
     * Stream text for a single Converse step.
     */
    public function generateStreamStep(
        string $invocationId,
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        ?int $timeout,
        StepContext $stepContext,
    ): Generator {
        /** @var Provider&TextProvider $provider */
        $client = $this->createBedrockClient($provider, $timeout);

        $parameters = $this->buildStepBody($provider, $model, $instructions, $messages, $tools, $schema, $options, $stepContext);

        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn (): Result => $client->converseStream($parameters),
            );
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        try {
            return yield from $this->processTextStream($invocationId, $provider, $model, $response['stream'], filled($schema));
        } catch (EventStreamDataException $exception) {
            yield (new Error(
                (string) Str::uuid(),
                $exception->getAwsErrorCode() ?? 'unknown_error',
                $exception->getAwsErrorMessage() ?? 'Unknown error',
                false,
                time(),
            ))->withInvocationId($invocationId);

            return null;
        }
    }

    /**
     * Build the Converse request parameters for the current text generation step.
     */
    protected function buildStepBody(
        TextProvider $provider,
        string $model,
        ?string $instructions,
        array $messages,
        array $tools,
        ?array $schema,
        ?TextGenerationOptions $options,
        StepContext $stepContext,
    ): array {
        $conversationMessages = $this->formatMessages($messages);
        $jsonSchema = $schema && $this->rejectsForcedToolChoice($model) ? $schema : null;
        $schemaTools = $schema && $jsonSchema === null ? $this->buildSchemaTools($schema, $tools) : null;
        $formattedTools = $schemaTools === null && $tools !== [] ? $this->formatTools($tools) : null;

        return $this->buildConverseParameters(
            $model,
            $instructions,
            $conversationMessages,
            $schemaTools,
            $formattedTools,
            $tools === [],
            $options,
            isFinalStep: $stepContext->isFinalStep,
            jsonSchema: $jsonSchema,
        );
    }

    /**
     * Extract usage data from a Converse response.
     */
    protected function extractUsage(array $data): TextUsage
    {
        $usage = $data['usage'] ?? [];
        $cacheReadTokens = $usage['cacheReadInputTokens'] ?? null;
        $cacheWriteTokens = $usage['cacheWriteInputTokens'] ?? null;

        return new TextUsage(
            inputTokens: ($usage['inputTokens'] ?? 0) + ($cacheReadTokens ?? 0) + ($cacheWriteTokens ?? 0),
            outputTokens: $usage['outputTokens'] ?? 0,
            cacheReadInputTokens: $cacheReadTokens,
            cacheWriteInputTokens: $cacheWriteTokens,
        );
    }

    /**
     * Parse a single Converse response into a step response.
     */
    protected function parseTextResponse(array $result, TextProvider $provider, string $model, bool $structured): StepResponse
    {
        $usage = $this->extractUsage($result);

        $output = '';
        $toolCalls = [];
        $replayBlocks = [];
        $structuredOutput = null;

        foreach ($result['output']['message']['content'] ?? [] as $block) {
            $replayBlocks[] = $block;

            if (isset($block['text'])) {
                $output .= $block['text'];

                continue;
            }

            if (! isset($block['toolUse'])) {
                continue;
            }

            if ($structured && $block['toolUse']['name'] === self::STRUCTURED_OUTPUT_TOOL) {
                $structuredOutput = json_encode($block['toolUse']['input'] ?? []);

                continue;
            }

            $toolCalls[] = new ToolCall(
                $block['toolUse']['toolUseId'],
                $block['toolUse']['name'],
                $block['toolUse']['input'] ?? [],
            );
        }

        if ($structured && $toolCalls === [] && $this->rejectsForcedToolChoice($model)) {
            $structuredOutput = $output;
        }

        $finishReason = $this->extractFinishReason($result);

        if ($toolCalls === [] && $structured && $finishReason === FinishReason::ToolCalls) {
            $finishReason = FinishReason::Stop;
        }

        return new StepResponse(
            text: $structuredOutput ?? $output,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $usage,
            meta: new Meta($provider->name(), $model),
            structured: $structuredOutput !== null ? $this->decodeStructuredOutput($structuredOutput) : null,
            replayBlocks: $replayBlocks,
            reasoning: $this->extractReasoning($replayBlocks),
        );
    }

    /**
     * Extract the reasoning text from Converse content blocks.
     */
    protected function extractReasoning(array $content): string
    {
        return static::joinReasoning(array_map(
            fn (array $block): string => $block['reasoningContent']['reasoningText']['text'] ?? '',
            $content,
        ));
    }

    /**
     * Stream a single Converse step, returning the parsed step response.
     *
     * @return Generator<int, StreamEvent, mixed, null|StepResponse>
     */
    protected function processTextStream(
        string $invocationId,
        TextProvider $provider,
        string $model,
        iterable $stream,
        bool $structured,
    ): Generator {
        $messageId = (string) Str::uuid();
        $timestamp = time();
        $totalUsage = new TextUsage;

        yield (new StreamStart(
            (string) Str::uuid(),
            $provider->name(),
            $model,
            $timestamp,
        ))->withInvocationId($invocationId);

        $assistantText = '';
        $pendingToolCalls = [];
        $toolCalls = [];
        $structuredOutput = null;
        $currentBlockIndex = null;
        $currentBlockType = '';
        $responseContent = [];
        $reasoningId = '';
        $textId = '';
        $currentText = '';
        $currentReasoningText = '';
        $currentReasoningSignature = '';
        $currentReasoningRedacted = '';
        $hasReasoningBlocks = false;
        $stopReason = '';

        $emitTextStart = function () use (&$textId, $invocationId, $timestamp): ?StreamEvent {
            if ($textId !== '') {
                return null;
            }

            $textId = (string) Str::uuid();

            return (new TextStart(
                (string) Str::uuid(),
                $textId,
                $timestamp,
            ))->withInvocationId($invocationId);
        };

        $emitReasoningStart = function () use (&$reasoningId, $invocationId, $timestamp): ?StreamEvent {
            if ($reasoningId !== '') {
                return null;
            }

            $reasoningId = (string) Str::uuid();

            return (new ReasoningStart(
                (string) Str::uuid(),
                $reasoningId,
                $timestamp,
            ))->withInvocationId($invocationId);
        };

        foreach ($stream as $event) {
            if (isset($event['contentBlockStart'])) {
                $currentBlockIndex = $event['contentBlockStart']['contentBlockIndex'] ?? 0;
                $start = $event['contentBlockStart']['start'] ?? [];
                $currentBlockType = isset($start['toolUse']) ? 'toolUse' : '';

                if (isset($start['toolUse'])) {
                    $pendingToolCalls[$currentBlockIndex] = [
                        'id' => $start['toolUse']['toolUseId'] ?? '',
                        'name' => $start['toolUse']['name'] ?? '',
                        'input' => '',
                    ];
                }

                continue;
            }

            if (isset($event['contentBlockDelta'])) {
                $index = $event['contentBlockDelta']['contentBlockIndex'] ?? $currentBlockIndex;
                $delta = $event['contentBlockDelta']['delta'] ?? [];

                if (isset($delta['text'])) {
                    $currentBlockType = 'text';

                    if ($delta['text'] !== '') {
                        if (($emittedEvent = $emitTextStart()) instanceof StreamEvent) {
                            yield $emittedEvent;
                        }

                        $assistantText .= $delta['text'];
                        $currentText .= $delta['text'];

                        yield (new TextDelta(
                            (string) Str::uuid(),
                            $textId,
                            $delta['text'],
                            $timestamp,
                        ))->withInvocationId($invocationId);
                    }
                } elseif (isset($delta['reasoningContent']['text']) && $delta['reasoningContent']['text'] !== '') {
                    $currentBlockType = 'reasoning';
                    $hasReasoningBlocks = true;

                    if (($emittedEvent = $emitReasoningStart()) instanceof StreamEvent) {
                        yield $emittedEvent;
                    }

                    $currentReasoningText .= $delta['reasoningContent']['text'];

                    yield (new ReasoningDelta(
                        (string) Str::uuid(),
                        $reasoningId,
                        $delta['reasoningContent']['text'],
                        $timestamp,
                    ))->withInvocationId($invocationId);
                } elseif (isset($delta['reasoningContent']['signature']) && $delta['reasoningContent']['signature'] !== '') {
                    $currentBlockType = 'reasoning';
                    $hasReasoningBlocks = true;

                    if (($emittedEvent = $emitReasoningStart()) instanceof StreamEvent) {
                        yield $emittedEvent;
                    }

                    $currentReasoningSignature .= $delta['reasoningContent']['signature'];
                } elseif (isset($delta['reasoningContent']['redactedContent']) && $delta['reasoningContent']['redactedContent'] !== '') {
                    $currentBlockType = 'reasoning';
                    $hasReasoningBlocks = true;

                    if (($emittedEvent = $emitReasoningStart()) instanceof StreamEvent) {
                        yield $emittedEvent;
                    }

                    $currentReasoningRedacted .= $delta['reasoningContent']['redactedContent'];
                } elseif (isset($delta['toolUse']['input'], $pendingToolCalls[$index])) {
                    $pendingToolCalls[$index]['input'] .= $delta['toolUse']['input'];
                }

                continue;
            }

            if (isset($event['contentBlockStop'])) {
                $index = $event['contentBlockStop']['contentBlockIndex'] ?? $currentBlockIndex;

                if ($currentBlockType === 'reasoning') {
                    if ($currentReasoningRedacted !== '') {
                        $responseContent[$index] = [
                            'reasoningContent' => [
                                'redactedContent' => $currentReasoningRedacted,
                            ],
                        ];
                    } else {
                        $reasoningText = ['text' => $currentReasoningText];

                        if ($currentReasoningSignature !== '') {
                            $reasoningText['signature'] = $currentReasoningSignature;
                        }

                        $responseContent[$index] = [
                            'reasoningContent' => [
                                'reasoningText' => $reasoningText,
                            ],
                        ];
                    }

                    yield (new ReasoningEnd(
                        (string) Str::uuid(),
                        $reasoningId,
                        $timestamp,
                    ))->withInvocationId($invocationId);

                    $currentReasoningText = '';
                    $currentReasoningSignature = '';
                    $currentReasoningRedacted = '';
                    $reasoningId = '';
                } elseif ($currentBlockType === 'text') {
                    $responseContent[$index] = ['text' => $currentText];

                    if ($textId !== '') {
                        yield (new TextEnd(
                            (string) Str::uuid(),
                            $textId,
                            $timestamp,
                        ))->withInvocationId($invocationId);
                    }

                    $currentText = '';
                    $textId = '';
                } elseif ($currentBlockType === 'toolUse' && isset($pendingToolCalls[$index])) {
                    $pending = $pendingToolCalls[$index];
                    $arguments = json_decode($pending['input'] !== '' ? $pending['input'] : '{}', true) ?? [];

                    if ($structured && $pending['name'] === self::STRUCTURED_OUTPUT_TOOL) {
                        $structuredOutput = json_encode($arguments);
                    } else {
                        $toolCall = new ToolCall($pending['id'], $pending['name'], $arguments);
                        $toolCalls[] = $toolCall;
                        $responseContent[$index] = [
                            'toolUse' => [
                                'toolUseId' => $toolCall->id,
                                'name' => $toolCall->name,
                                'input' => $arguments,
                            ],
                        ];

                        yield (new ToolCallEvent(
                            (string) Str::uuid(),
                            $toolCall,
                            $timestamp,
                        ))->withInvocationId($invocationId);
                    }

                    unset($pendingToolCalls[$index]);
                }

                $currentBlockType = '';

                continue;
            }

            if (isset($event['messageStop'])) {
                $stopReason = $event['messageStop']['stopReason'] ?? '';

                continue;
            }

            if (isset($event['metadata']['usage'])) {
                $totalUsage = $totalUsage->add($this->extractUsage($event['metadata']));
            }
        }

        // A complete HTTP transfer does not guarantee a complete model response.
        if ($stopReason === '') {
            yield (new Error(
                (string) Str::uuid(),
                'incomplete_stream',
                'The provider stream ended before the response was complete.',
                false,
                time(),
            ))->withInvocationId($invocationId);

            return null;
        }

        if ($structuredOutput !== null) {
            yield (new TextStart(
                (string) Str::uuid(),
                $messageId,
                $timestamp,
            ))->withInvocationId($invocationId);

            yield (new TextDelta(
                (string) Str::uuid(),
                $messageId,
                $structuredOutput,
                $timestamp,
            ))->withInvocationId($invocationId);

            yield (new TextEnd(
                (string) Str::uuid(),
                $messageId,
                $timestamp,
            ))->withInvocationId($invocationId);
        }

        if ($structured && $toolCalls === [] && $this->rejectsForcedToolChoice($model)) {
            $structuredOutput = $assistantText;
        }

        $finishReason = $this->extractFinishReason(['stopReason' => $stopReason]);

        if ($toolCalls === [] && $structured && $finishReason === FinishReason::ToolCalls) {
            $finishReason = FinishReason::Stop;
        }

        $replayBlocks = array_values($responseContent);

        if (! $hasReasoningBlocks) {
            $replayBlocks = array_values(array_filter(
                $replayBlocks,
                fn (array $block): bool => ! isset($block['text']) || $block['text'] !== '',
            ));
        }

        return new StepResponse(
            text: $structuredOutput ?? $assistantText,
            toolCalls: $toolCalls,
            finishReason: $finishReason,
            usage: $totalUsage,
            meta: new Meta($provider->name(), $model),
            structured: $structuredOutput !== null ? $this->decodeStructuredOutput($structuredOutput) : null,
            replayBlocks: $replayBlocks,
        );
    }

    /**
     * Generate embeddings using AWS Bedrock.
     */
    public function generateEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        array $inputs,
        int $dimensions,
        int $timeout = 30,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        /** @var EmbeddingProvider&Provider $provider */
        $client = $this->createBedrockClient($provider, $timeout);

        if ($this->isCohereEmbeddingModel($model)) {
            return $this->generateCohereEmbeddings($provider, $model, $client, $inputs, $providerOptions);
        }

        $embeddings = [];
        $totalTokens = 0;

        foreach ($inputs as $input) {
            try {
                $response = $this->withErrorHandling(
                    $provider->name(),
                    fn (): Result => $client->invokeModel([
                        'modelId' => $model,
                        'contentType' => 'application/json',
                        'accept' => 'application/json',
                        'body' => json_encode(array_merge($providerOptions, [
                            'inputText' => $input,
                            'dimensions' => $dimensions,
                        ])),
                    ]),
                );

                $result = json_decode((string) $response->get('body')->getContents(), true);
            } catch (Throwable $e) {
                throw BedrockException::toAiException($e, $provider->name(), $model);
            }

            $embeddings[] = $result['embedding'] ?? $result['embeddingsByType']['binary']
                ?? throw new AiException('Bedrock returned no embedding for the input.');

            $totalTokens += $result['inputTextTokenCount'] ?? 0;
        }

        return new EmbeddingsResponse(
            $embeddings,
            new Usage($totalTokens),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate embeddings using a Cohere Bedrock model in a single batched call.
     *
     * @param array<string> $inputs
     */
    protected function generateCohereEmbeddings(
        EmbeddingProvider $provider,
        string $model,
        BedrockRuntimeClient $client,
        array $inputs,
        array $providerOptions = [],
    ): EmbeddingsResponse {
        try {
            $response = $this->withErrorHandling(
                $provider->name(),
                fn (): Result => $client->invokeModel([
                    'modelId' => $model,
                    'contentType' => 'application/json',
                    'accept' => 'application/json',
                    'body' => json_encode(array_merge(
                        ['input_type' => 'search_document'],
                        $providerOptions,
                        ['texts' => array_values($inputs)],
                    )),
                ]),
            );

            $result = json_decode((string) $response->get('body')->getContents(), true);
        } catch (Throwable $throwable) {
            throw BedrockException::toAiException($throwable, $provider->name(), $model);
        }

        // Cohere's Bedrock response body carries no usage, but the input token
        // count is reported in the `x-amzn-bedrock-input-token-count` header.
        $inputTokens = (int) ($response->get('@metadata')['headers']['x-amzn-bedrock-input-token-count'] ?? 0);

        return new EmbeddingsResponse(
            $this->parseCohereEmbeddings($result['embeddings'] ?? []),
            new Usage($inputTokens),
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Determine if the given model identifier refers to a Cohere embeddings model.
     */
    protected function isCohereEmbeddingModel(string $model): bool
    {
        return str_contains($model, 'cohere.embed-');
    }

    /**
     * Resolve the maximum number of steps for the given tools and options.
     *
     * @param array<Tool> $tools
     */
    protected function resolveMaxSteps(array $tools, ?TextGenerationOptions $options): int
    {
        if ($tools === []) {
            return 1;
        }

        return (int) ($options->maxSteps ?? round(count($tools) * 1.5));
    }

    /**
     * Extract and map the finish reason from the Bedrock Converse response.
     */
    protected function extractFinishReason(array $data): FinishReason
    {
        return match ($data['stopReason'] ?? '') {
            'end_turn', 'stop_sequence' => FinishReason::Stop,
            'tool_use' => FinishReason::ToolCalls,
            'max_tokens' => FinishReason::Length,
            'content_filtered', 'guardrail_intervened' => FinishReason::ContentFilter,
            default => FinishReason::Unknown,
        };
    }

    /**
     * Build the request parameters for the Bedrock Converse API.
     *
     * @param null|array<string, mixed> $schemaTools
     * @param null|array<string, mixed> $formattedTools pre-formatted real tools (used when no schema is active)
     * @param bool $toolsEmpty whether the caller passed any real tools at all
     * @param null|array<string, mixed> $jsonSchema
     */
    protected function buildConverseParameters(
        string $model,
        ?string $instructions,
        array $conversationMessages,
        ?array $schemaTools,
        ?array $formattedTools,
        bool $toolsEmpty,
        ?TextGenerationOptions $options,
        bool $isFinalStep,
        ?array $jsonSchema = null,
    ): array {
        $parameters = [
            'modelId' => $model,
            'messages' => $conversationMessages,
        ];

        $providerOptions = $options?->providerOptions(Lab::Bedrock) ?? [];

        if ($instructions) {
            $parameters['system'] = [['text' => $instructions]];
        }

        $toolConfig = $this->buildToolConfig($schemaTools, $formattedTools, $toolsEmpty, $isFinalStep);

        if ($toolConfig !== null) {
            $parameters['toolConfig'] = $toolConfig;
        }

        $inferenceConfig = $this->buildInferenceConfig($options);

        if ($inferenceConfig !== []) {
            $parameters['inferenceConfig'] = $inferenceConfig;
        }

        $parameters = array_merge($parameters, $providerOptions);

        if ($jsonSchema !== null) {
            $parameters['system'][] = ['text' => $this->jsonInstruction($jsonSchema)];
        }

        $this->ensureValidPromptCacheOrder($options);

        if (isset($parameters['system']) && $options?->cacheInstructions instanceof CacheInstructions) {
            $parameters['system'][] = $this->cachePoint($options->cacheInstructions->ttl);
        }

        if (isset($parameters['toolConfig']['tools']) && $options?->cacheToolDefinitions instanceof CacheToolDefinitions) {
            $parameters['toolConfig']['tools'][] = $this->cachePoint($options->cacheToolDefinitions->ttl);
        }

        return $parameters;
    }

    /**
     * Ensure longer-lived cache points precede shorter-lived cache points.
     */
    protected function ensureValidPromptCacheOrder(?TextGenerationOptions $options): void
    {
        if ($options?->cacheInstructions?->ttl === '1h'
            && $options->cacheToolDefinitions instanceof CacheToolDefinitions
            && $options->cacheToolDefinitions->ttl !== '1h') {
            throw new InvalidArgumentException('A one-hour instructions cache requires the tool definitions cache to also use a one-hour TTL.');
        }
    }

    /**
     * Build a Bedrock cache point for the requested TTL.
     */
    protected function cachePoint(?string $ttl): array
    {
        return ['cachePoint' => Arr::whereNotNull(['type' => 'default', 'ttl' => $ttl])];
    }

    /**
     * Build the inferenceConfig block for Bedrock's Converse API.
     */
    protected function buildInferenceConfig(?TextGenerationOptions $options): array
    {
        if (! $options instanceof TextGenerationOptions) {
            return [];
        }

        return Arr::whereNotNull([
            'maxTokens' => $options->maxTokens,
            'temperature' => $options->temperature,
            'topP' => $options->topP,
        ]);
    }

    /**
     * Build the assistant conversation message block combining text and tool calls.
     *
     * @param array<ToolCall> $toolCalls
     * @param array<int, array<string, mixed>> $replayBlocks
     */
    protected function buildAssistantConversationMessage(string $text, array $toolCalls, array $replayBlocks = []): array
    {
        return $this->formatAssistantMessage(
            new AssistantMessage($text, new Collection($toolCalls), $replayBlocks)
        );
    }

    /**
     * Cast empty toolUse.input arrays to objects so the Converse API doesn't reject them.
     *
     * @param array<int, array<string, mixed>> $content
     * @return array<int, array<string, mixed>>
     */
    protected function ensureToolInputIsObject(array $content): array
    {
        return array_map(function (array $block): array {
            if (isset($block['toolUse'])) {
                $block['toolUse']['input'] = (object) ($block['toolUse']['input'] ?? []);
            }

            return $block;
        }, $content);
    }

    /**
     * Build the user conversation message block carrying tool results.
     *
     * @param array<ToolResult> $toolResults
     */
    protected function buildToolResultConversationMessage(array $toolResults): array
    {
        return [
            'role' => 'user',
            'content' => array_map(fn (ToolResult $toolResult): array => [
                'toolResult' => [
                    'toolUseId' => $toolResult->id,
                    'content' => [
                        ['text' => $toolResult->text()],
                    ],
                ],
            ], $toolResults),
        ];
    }

    /**
     * Determine if the model rejects a forced tool choice, so structured output must come from the text answer.
     */
    protected function rejectsForcedToolChoice(string $model): bool
    {
        return Str::contains($model, self::MODELS_REJECTING_FORCED_TOOL_CHOICE);
    }

    /**
     * Build the system instruction that asks for the final answer as JSON matching the schema.
     */
    protected function jsonInstruction(array $schema): string
    {
        return "JSON schema:\n" . json_encode((new ObjectSchema($schema))->toArray())
            . "\n\nYou must answer with only a JSON object that matches the JSON schema above. Do not wrap it in markdown fences or include any other text.";
    }

    /**
     * Build the synthetic structured-output tool plus any real tools.
     */
    protected function buildSchemaTools(array $schema, array $tools): array
    {
        $schemaTools = [
            [
                'toolSpec' => [
                    'name' => self::STRUCTURED_OUTPUT_TOOL,
                    'description' => 'Return the response as a structured JSON object matching the provided schema.',
                    'inputSchema' => [
                        'json' => (new ObjectSchema($schema))->toArray(),
                    ],
                ],
            ],
        ];

        return array_merge($schemaTools, $this->formatTools($tools));
    }

    /**
     * Build Bedrock's toolConfig for the current step.
     *
     * When a schema is present, toolChoice is only forced to the synthetic tool on the
     * final step so real tools can be invoked on earlier iterations.
     */
    protected function buildToolConfig(?array $schemaTools, ?array $formattedTools, bool $toolsEmpty, bool $isFinalStep): ?array
    {
        if ($schemaTools !== null) {
            return [
                'tools' => $schemaTools,
                'toolChoice' => ($isFinalStep || $toolsEmpty)
                    ? ['tool' => ['name' => self::STRUCTURED_OUTPUT_TOOL]]
                    : ['auto' => []],
            ];
        }

        if ($formattedTools !== null) {
            return ['tools' => $formattedTools];
        }

        return null;
    }

    /**
     * Format Hypervel AI messages for Bedrock's Converse API.
     */
    protected function formatMessages(array $messages): array
    {
        return (new Collection($messages))->map(fn (AssistantMessage|ToolResultMessage|UserMessage|Message|array $message): array => match (true) {
            $message instanceof AssistantMessage => $this->formatAssistantMessage($message),
            $message instanceof ToolResultMessage => $this->formatToolResultMessage($message),
            $message instanceof UserMessage => $this->formatUserMessage($message),
            $message instanceof Message => $this->formatGenericMessage($message),
            default => $this->formatArrayMessage($message),
        })->all();
    }

    /**
     * Format an AssistantMessage for the Converse API.
     */
    protected function formatAssistantMessage(AssistantMessage $message): array
    {
        if (filled($message->replayBlocks)) {
            return [
                'role' => 'assistant',
                'content' => $this->ensureToolInputIsObject($message->replayBlocks),
            ];
        }

        $content = [];

        if ($message->content !== '') {
            $content[] = ['text' => $message->content];
        }

        foreach ($message->toolCalls as $toolCall) {
            $content[] = [
                'toolUse' => [
                    'toolUseId' => $toolCall->id,
                    'name' => $toolCall->name,
                    'input' => array_is_list($toolCall->arguments) ? (object) $toolCall->arguments : $toolCall->arguments,
                ],
            ];
        }

        return ['role' => 'assistant', 'content' => $content];
    }

    /**
     * Format a ToolResultMessage for the Converse API.
     */
    protected function formatToolResultMessage(ToolResultMessage $message): array
    {
        $content = [];

        foreach ($message->toolResults as $toolResult) {
            $content[] = [
                'toolResult' => [
                    'toolUseId' => $toolResult->id,
                    'content' => [
                        ['text' => $toolResult->text()],
                    ],
                ],
            ];
        }

        return ['role' => 'user', 'content' => $content];
    }

    /**
     * Format a UserMessage and its attachments for the Converse API.
     */
    protected function formatUserMessage(UserMessage $message): array
    {
        $content = [['text' => $message->content]];

        if ($message->attachments->isNotEmpty()) {
            $content = array_merge($content, $this->mapAttachments($message->attachments));
        }

        return ['role' => 'user', 'content' => $content];
    }

    /**
     * Format a generic Message (system/user/assistant) for the Converse API.
     */
    protected function formatGenericMessage(Message $message): array
    {
        return [
            'role' => $message->role === MessageRole::Assistant ? 'assistant' : 'user',
            'content' => [['text' => $message->content]],
        ];
    }

    /**
     * Format a raw array-shaped message for the Converse API.
     *
     * @param array{role: string, content: string} $message
     */
    protected function formatArrayMessage(array $message): array
    {
        return [
            'role' => $message['role'] === MessageRole::Assistant->value ? 'assistant' : 'user',
            'content' => [['text' => $message['content']]],
        ];
    }

    /**
     * Format tools for the Converse API.
     *
     * @param array<Tool> $tools
     */
    protected function formatTools(array $tools): array
    {
        return (new Collection($tools))
            ->filter(fn (mixed $tool): bool => $tool instanceof Tool)
            ->map(fn (Tool $tool): array => [
                'toolSpec' => [
                    'name' => ToolNameResolver::resolve($tool),
                    'description' => (string) $tool->description(),
                    'inputSchema' => [
                        'json' => (new ObjectSchema($tool->schema(new JsonSchemaTypeFactory)))->toArray(),
                    ],
                ],
            ])
            ->values()
            ->all();
    }
}
