<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts\Gateway;

use Hypervel\Ai\Contracts\Providers\ClassificationProvider;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Http\UploadedFile;

interface ClassificationGateway
{
    /**
     * Answer the given questions about the state.
     *
     * @param array<string, mixed>|string $state
     * @param array<string, Question> $questions
     * @param array<string, mixed> $providerOptions
     * @param array<int, File|UploadedFile> $attachments
     */
    public function classify(
        ClassificationProvider $provider,
        string $model,
        string|array $state,
        array $questions,
        int $timeout = 30,
        array $providerOptions = [],
        array $attachments = [],
    ): ClassificationResponse;
}
