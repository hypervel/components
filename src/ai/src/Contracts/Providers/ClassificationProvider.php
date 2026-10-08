<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Providers;

use Hypervel\Ai\Contracts\Gateway\ClassificationGateway;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Http\UploadedFile;

interface ClassificationProvider extends Provider
{
    /**
     * Answer the given questions about the state.
     *
     * @param array<string, mixed>|string $state
     * @param array<string, Question> $questions
     * @param array<string, mixed> $providerOptions
     * @param array<int, File|UploadedFile> $attachments
     */
    public function classify(string|array $state, array $questions, ?string $model = null, int $timeout = 30, array $providerOptions = [], array $attachments = []): ClassificationResponse;

    /**
     * Get the provider's classification gateway.
     */
    public function classificationGateway(): ClassificationGateway;

    /**
     * Set the provider's classification gateway.
     */
    public function useClassificationGateway(ClassificationGateway $gateway): self;

    /**
     * Get the name of the default classification model.
     */
    public function defaultClassificationModel(): string;
}
