<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Closure;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Gateway\FakeClassificationGateway;
use Hypervel\Ai\PendingResponses\PendingClassification;
use Hypervel\Http\UploadedFile;
use InvalidArgumentException;

class Classification
{
    /**
     * Create a new pending classification for the given state.
     *
     * @param array<string, mixed>|string $state
     * @param array<int, File|UploadedFile> $attachments
     *
     * @throws InvalidArgumentException if the state is blank
     */
    public static function of(string|array $state, array $attachments = []): PendingClassification
    {
        return new PendingClassification($state, $attachments);
    }

    /**
     * Fake classification operations.
     *
     * Tests only. The fake gateway is shared across requests in the worker.
     */
    public static function fake(Closure|array $responses = []): FakeClassificationGateway
    {
        return Ai::fakeClassification($responses);
    }

    /**
     * Assert that a classification was performed matching a given truth test.
     */
    public static function assertClassified(Closure $callback): void
    {
        Ai::assertClassified($callback);
    }

    /**
     * Assert that a classification was not performed matching a given truth test.
     */
    public static function assertNotClassified(Closure $callback): void
    {
        Ai::assertNotClassified($callback);
    }

    /**
     * Assert that no classifications were performed.
     */
    public static function assertNothingClassified(): void
    {
        Ai::assertNothingClassified();
    }

    /**
     * Determine if classification is faked.
     */
    public static function isFaked(): bool
    {
        return Ai::classificationIsFaked();
    }
}
