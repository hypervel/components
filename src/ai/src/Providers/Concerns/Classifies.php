<?php

declare(strict_types=1);

namespace Hypervel\Ai\Providers\Concerns;

use Hypervel\Ai\Ai;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Events\Classified;
use Hypervel\Ai\Events\Classifying;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Str;
use LogicException;

trait Classifies
{
    /**
     * Answer the given questions about the state.
     *
     * @param array<string, mixed>|string $state
     * @param array<string, Question> $questions
     * @param array<string, mixed> $providerOptions
     * @param array<int, File|UploadedFile> $attachments
     */
    public function classify(string|array $state, array $questions, ?string $model = null, int $timeout = 30, array $providerOptions = [], array $attachments = []): ClassificationResponse
    {
        if ($attachments !== [] && ! $this->supportsClassificationAttachments()) {
            throw new LogicException("Provider [{$this->name()}] does not support classification attachments.");
        }

        $invocationId = (string) Str::uuid7();

        $model ??= $this->defaultClassificationModel();

        $prompt = new ClassificationPrompt($state, $questions, $this, $model, $timeout, $providerOptions, $attachments);

        if (Ai::classificationIsFaked()) {
            Ai::recordClassification($prompt);
        }

        if ($this->events->hasListeners(Classifying::class)) {
            $this->events->dispatch(new Classifying(
                $invocationId,
                $this,
                $model,
                $prompt,
            ));
        }

        return tap($this->classificationGateway()->classify(
            $this,
            $model,
            $state,
            $questions,
            $timeout,
            $providerOptions,
            $attachments,
        ), function (ClassificationResponse $response) use ($invocationId, $model, $prompt): void {
            if ($this->events->hasListeners(Classified::class)) {
                $this->events->dispatch(new Classified(
                    $invocationId,
                    $this,
                    $model,
                    $prompt,
                    $response,
                ));
            }
        });
    }

    /**
     * Determine if the provider can classify attachments alongside the state.
     */
    public function supportsClassificationAttachments(): bool
    {
        return false;
    }
}
