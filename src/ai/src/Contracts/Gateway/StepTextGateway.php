<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Gateway;

use Generator;
use Hypervel\Ai\Contracts\Providers\TextProvider;
use Hypervel\Ai\Contracts\Tool;
use Hypervel\Ai\Gateway\StepContext;
use Hypervel\Ai\Gateway\StepResponse;
use Hypervel\Ai\Gateway\TextGenerationOptions;
use Hypervel\Ai\Messages\Message;
use Hypervel\Ai\Streaming\Events\StreamEvent;
use Hypervel\JsonSchema\Types\Type;

interface StepTextGateway
{
    /**
     * Generate text for a single step in a conversation.
     *
     * @param Message[] $messages
     * @param Tool[] $tools
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
    ): StepResponse;

    /**
     * Stream text for a single step in a conversation.
     *
     * @param Message[] $messages
     * @param Tool[] $tools
     * @param null|array<string, Type> $schema
     * @return Generator<int, StreamEvent, mixed, null|StepResponse>
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
    ): Generator;
}
