<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Generator;
use Hypervel\Ai\Approvals\PendingApproval;
use Hypervel\Ai\Contracts\Gateway\StepTextGateway;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Messages\UserMessage;
use Hypervel\Ai\Responses\AgentResponse;
use Hypervel\Ai\Responses\Data\FinishReason;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Ai\Responses\Data\ToolCall;
use Hypervel\Ai\Responses\StructuredTextResponse;
use Hypervel\Ai\Responses\TextResponse;
use Hypervel\Ai\Streaming\Events\Citation as CitationEvent;
use Hypervel\Ai\Streaming\Events\ReasoningDelta;
use Hypervel\Ai\Streaming\Events\ReasoningEnd;
use Hypervel\Ai\Streaming\Events\ReasoningStart;
use Hypervel\Ai\Streaming\Events\StreamStart;
use Hypervel\Ai\Streaming\Events\TextDelta;
use Hypervel\Ai\Streaming\Events\TextEnd;
use Hypervel\Ai\Streaming\Events\TextStart;
use Hypervel\Ai\Streaming\Events\ToolCall as ToolCallEvent;
use Hypervel\JsonSchema\Types\ObjectType;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use RuntimeException;

use function Hypervel\Ai\generate_fake_data_for_json_schema_type;
use function Hypervel\Ai\ulid;

class FakeTextGateway implements StepTextGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayPrompts = false;

    /**
     * Create a text gateway with fake responses.
     */
    public function __construct(
        protected Closure|array $responses,
    ) {
    }

    /**
     * Generate text for a single step in a conversation.
     *
     * @param null|array<string, Type> $schema
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
        return $this->nextStep($provider, $model, $messages, $schema);
    }

    /**
     * Stream text for a single step in a conversation.
     *
     * @param null|array<string, Type> $schema
     * @return Generator<int, mixed, mixed, StepResponse>
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
        $step = $this->nextStep($provider, $model, $messages, $schema);

        $messageId = ulid();

        yield (new StreamStart(ulid(), $provider->name(), $model, time()))->withInvocationId($invocationId);

        if (filled($step->reasoning)) {
            $reasoningId = ulid();

            yield (new ReasoningStart(ulid(), $reasoningId, time()))->withInvocationId($invocationId);

            foreach (Str::of($step->reasoning)->explode(' ') as $index => $word) {
                yield (new ReasoningDelta(
                    ulid(),
                    $reasoningId,
                    $index > 0 ? ' ' . $word : $word,
                    time(),
                ))->withInvocationId($invocationId);
            }

            yield (new ReasoningEnd(ulid(), $reasoningId, time()))->withInvocationId($invocationId);
        }

        if (filled($step->text)) {
            yield (new TextStart(ulid(), $messageId, time()))->withInvocationId($invocationId);

            foreach (Str::of($step->text)->explode(' ') as $index => $word) {
                yield (new TextDelta(
                    ulid(),
                    $messageId,
                    $index > 0 ? ' ' . $word : $word,
                    time(),
                ))->withInvocationId($invocationId);
            }

            yield (new TextEnd(ulid(), $messageId, time()))->withInvocationId($invocationId);
        }

        foreach ($step->meta->citations as $citation) {
            yield (new CitationEvent(ulid(), $messageId, $citation, time()))->withInvocationId($invocationId);
        }

        foreach ($step->toolCalls as $toolCall) {
            yield (new ToolCallEvent(ulid(), $toolCall, time()))->withInvocationId($invocationId);
        }

        return $step;
    }

    /**
     * Resolve the next fake response and marshal it into a step response.
     */
    protected function nextStep(TextProvider $provider, string $model, array $messages, ?array $schema): StepResponse
    {
        $message = (new Collection($messages))->last(fn (mixed $message): bool => $message instanceof UserMessage);

        $prompt = $message instanceof UserMessage ? $message->content : '';
        $attachments = $message instanceof UserMessage ? $message->attachments : new Collection;

        $response = $this->nextResponse(
            $provider,
            $model,
            $prompt,
            $attachments,
            $schema
        );

        return $this->toStepResponse($response, $provider, $model)
            ->withRawResponse($response instanceof TextResponse ? $response->raw : null);
    }

    /**
     * Convert a marshalled fake response into a step response for the generation loop.
     */
    protected function toStepResponse(mixed $response, TextProvider $provider, string $model): StepResponse
    {
        if ($response instanceof ToolCall) {
            return new StepResponse(
                '',
                [$response],
                FinishReason::ToolCalls,
                new TextUsage,
                new Meta($provider->name(), $model)
            );
        }

        if ($response instanceof StructuredTextResponse) {
            return new StepResponse(
                $response->text,
                [],
                FinishReason::Stop,
                $response->usage,
                $response->meta,
                $response->structured
            );
        }

        if ($response instanceof TextResponse && $response->hasPendingApprovals()) {
            $toolCalls = $response->pendingApprovals->map(
                fn (PendingApproval $approval): ToolCall => new ToolCall($approval->id, $approval->tool, $approval->arguments),
            )->all();

            return new StepResponse(
                $response->text,
                $toolCalls,
                FinishReason::ToolCalls,
                $response->usage,
                $response->meta,
                pendingApprovals: $response->pendingApprovals->all(),
            );
        }

        return new StepResponse(
            $response->text,
            [],
            FinishReason::Stop,
            $response->usage,
            $response->meta,
            reasoning: $response instanceof AgentResponse ? $response->reasoning : '',
        );
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(TextProvider $provider, string $model, string $prompt, Collection $attachments, ?array $schema): mixed
    {
        // Reserve the entry first; callbacks may yield or throw.
        $index = $this->currentResponseIndex++;

        $response = is_array($this->responses)
            ? ($this->responses[$index] ?? null)
            : call_user_func($this->responses, $prompt, $attachments, $provider, $model);

        return $this->marshalResponse(
            $response,
            $provider,
            $model,
            $prompt,
            $attachments,
            $schema
        );
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        TextProvider $provider,
        string $model,
        string $prompt,
        Collection $attachments,
        ?array $schema
    ): mixed {
        if (is_null($response)) {
            if ($this->preventStrayPrompts) {
                throw new RuntimeException('Attempted prompt [' . Str::words($prompt, 10) . '] without a fake agent response.');
            }

            $response = is_null($schema)
                ? 'Fake response for prompt: ' . Str::words($prompt, 10)
                : generate_fake_data_for_json_schema_type(new ObjectType($schema));
        }

        return match (true) {
            is_string($response) => new TextResponse(
                $response,
                new TextUsage,
                new Meta($provider->name(), $model)
            ),
            is_array($response) => new StructuredTextResponse(
                $response,
                json_encode($response),
                new TextUsage,
                new Meta($provider->name(), $model)
            ),
            $response instanceof Closure => $this->marshalResponse(
                $response($prompt, $attachments, $provider, $model),
                $provider,
                $model,
                $prompt,
                $attachments,
                $schema
            ),
            default => $response,
        };
    }

    /**
     * Indicate that an exception should be thrown if any prompt is not faked.
     *
     * Tests only. This setting affects the fake gateway shared by requests in the worker.
     */
    public function preventStrayPrompts(bool $prevent = true): self
    {
        $this->preventStrayPrompts = $prevent;

        return $this;
    }
}
