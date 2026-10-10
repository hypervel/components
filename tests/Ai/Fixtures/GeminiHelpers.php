<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Fixtures;

use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Http;
use Hypervel\Tests\Ai\Fixtures\Agents\AssistantAgent;

trait GeminiHelpers
{
    /**
     * Build a completed interaction payload.
     */
    protected function fakeInteraction(array $steps, array $usage = [], string $status = 'completed'): array
    {
        return [
            'id' => 'int_123',
            'model' => 'gemini-3.7-flash',
            'status' => $status,
            'steps' => $steps,
            'usage' => array_merge([
                'total_input_tokens' => 10,
                'total_output_tokens' => 5,
                'total_tokens' => 15,
            ], $usage),
        ];
    }

    /**
     * Build a model output step.
     */
    protected function modelOutput(string $text, array $annotations = []): array
    {
        return [
            'type' => 'model_output',
            'status' => 'done',
            'content' => [array_filter([
                'type' => 'text',
                'text' => $text,
                'annotations' => $annotations ?: null,
            ])],
        ];
    }

    /**
     * Build a thought step.
     */
    protected function thoughtStep(string $text): array
    {
        return [
            'type' => 'thought',
            'status' => 'done',
            'summary' => [['type' => 'text', 'text' => $text]],
        ];
    }

    /**
     * Build a function call step.
     */
    protected function functionCallStep(string $name, array $arguments = [], string $id = 'call_123'): array
    {
        return [
            'type' => 'function_call',
            'status' => 'done',
            'id' => $id,
            'name' => $name,
            'arguments' => (object) $arguments,
        ];
    }

    /**
     * Create a text response.
     */
    protected function fakeTextResponse(string $text = 'Hello'): PromiseInterface
    {
        return Http::response($this->fakeInteraction([$this->modelOutput($text)]));
    }

    /**
     * Create a response with thought usage.
     */
    protected function fakeThinkingResponse(array $steps): PromiseInterface
    {
        return Http::response($this->fakeInteraction($steps, ['total_thought_tokens' => 3, 'total_tokens' => 18]));
    }

    /**
     * Create a tool call response.
     */
    protected function fakeToolCallResponse(string $toolName = 'FixedNumberGenerator', ?string $callId = null): PromiseInterface
    {
        return Http::response($this->fakeInteraction([
            $this->functionCallStep($toolName, [], $callId ?? 'call_123'),
        ]));
    }

    /**
     * Create a structured text response.
     */
    protected function fakeStructuredResponse(array $data): PromiseInterface
    {
        return Http::response($this->fakeInteraction([$this->modelOutput(json_encode($data))]));
    }

    /**
     * Create a tool call response with a unique call ID.
     */
    protected function fakeUniqueToolCallResponse(): PromiseInterface
    {
        return Http::response($this->fakeInteraction([
            $this->functionCallStep('FixedNumberGenerator', [], 'call_' . uniqid()),
        ]));
    }

    /**
     * Collect an agent's Gemini stream events.
     */
    protected function collectStreamEvents(?object $agent = null): array
    {
        $agent ??= new AssistantAgent;

        $response = $agent->stream(
            'Hello',
            provider: 'gemini',
        );

        $events = [];

        foreach ($response as $event) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * Encode events as an SSE payload.
     */
    protected function ssePayload(array $events): string
    {
        $lines = [];

        foreach ($events as $event) {
            $lines[] = 'data: ' . json_encode($event);
        }

        return implode("\n\n", $lines) . "\n\n";
    }

    /**
     * Build a step start event.
     */
    protected function stepStart(int $index, array $step): array
    {
        return ['event_type' => 'step.start', 'index' => $index, 'step' => $step];
    }

    /**
     * Build a text delta event.
     */
    protected function stepDelta(int $index, string $type, string $text): array
    {
        return ['event_type' => 'step.delta', 'index' => $index, 'delta' => ['type' => $type, 'text' => $text]];
    }

    /**
     * Build a function argument delta event.
     */
    protected function argumentsDelta(int $index, string $partial): array
    {
        return ['event_type' => 'step.delta', 'index' => $index, 'delta' => ['type' => 'arguments_delta', 'arguments' => $partial]];
    }

    /**
     * Build a step stop event.
     */
    protected function stepStop(int $index): array
    {
        return ['event_type' => 'step.stop', 'index' => $index];
    }

    /**
     * Build an interaction completion event.
     */
    protected function interactionCompleted(array $usage = [], string $status = 'completed'): array
    {
        // Gemini's completed event carries the usage and status only, never the steps...
        return [
            'event_type' => 'interaction.completed',
            'interaction' => Arr::except($this->fakeInteraction([], $usage, $status), 'steps'),
        ];
    }
}
