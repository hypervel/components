<?php

declare(strict_types=1);

namespace Hypervel\Tests\Ai\Feature;

use Closure;
use Hypervel\Ai\Audio;
use Hypervel\Ai\Classification;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Embeddings;
use Hypervel\Ai\Events\AudioFailed;
use Hypervel\Ai\Events\ClassificationFailed;
use Hypervel\Ai\Events\Classifying;
use Hypervel\Ai\Events\EmbeddingsFailed;
use Hypervel\Ai\Events\EmbeddingsGenerated;
use Hypervel\Ai\Events\GeneratingAudio;
use Hypervel\Ai\Events\GeneratingEmbeddings;
use Hypervel\Ai\Events\GeneratingImage;
use Hypervel\Ai\Events\GeneratingTranscription;
use Hypervel\Ai\Events\ImageFailed;
use Hypervel\Ai\Events\Reranking;
use Hypervel\Ai\Events\RerankingFailed;
use Hypervel\Ai\Events\TranscriptionFailed;
use Hypervel\Ai\Exceptions\RateLimitedException;
use Hypervel\Ai\Image;
use Hypervel\Ai\Reranking as RerankingOperation;
use Hypervel\Ai\Transcription;
use Hypervel\Support\Facades\Event;
use Hypervel\Tests\Ai\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Throwable;

class OperationEventsTest extends TestCase
{
    #[DataProvider('operations')]
    public function testOperationFailuresDispatchAnEventAndRethrowTheOriginalException(Closure $fake, Closure $invoke, string $startingEvent, string $failureEvent): void
    {
        Event::fake();

        $exception = new RuntimeException('Provider failed.');
        $fake(fn () => throw $exception);

        $caught = null;

        try {
            $invoke();
        } catch (Throwable $e) {
            $caught = $e;
        }

        Event::assertDispatchedTimes($startingEvent, 1);
        Event::assertDispatchedTimes($failureEvent, 1);

        $started = Event::dispatched($startingEvent)->first()[0];
        $failed = Event::dispatched($failureEvent)->first()[0];

        $this->assertSame($exception, $caught);
        $this->assertSame($started->invocationId, $failed->invocationId);
        $this->assertSame($started->provider, $failed->provider);
        $this->assertSame($started->model, $failed->model);
        $this->assertSame($started->prompt, $failed->prompt);
        $this->assertSame($exception, $failed->exception);
    }

    #[DataProvider('operations')]
    public function testSuccessfulOperationsDoNotDispatchFailureEvents(Closure $fake, Closure $invoke, string $startingEvent, string $failureEvent): void
    {
        Event::fake();

        $fake([]);
        $invoke();

        Event::assertDispatchedTimes($startingEvent, 1);
        Event::assertNotDispatched($failureEvent);
    }

    /**
     * Provide each model operation with its fake, invocation, and starting and failure events.
     */
    public static function operations(): array
    {
        return [
            'embeddings' => [
                fn ($responses) => Embeddings::fake($responses),
                fn () => Embeddings::for(['Hello world'])->generate(),
                GeneratingEmbeddings::class,
                EmbeddingsFailed::class,
            ],
            'image' => [
                fn ($responses) => Image::fake($responses),
                fn () => Image::of('A sunset')->generate(),
                GeneratingImage::class,
                ImageFailed::class,
            ],
            'audio' => [
                fn ($responses) => Audio::fake($responses),
                fn () => Audio::of('Hello world')->generate(),
                GeneratingAudio::class,
                AudioFailed::class,
            ],
            'transcription' => [
                fn ($responses) => Transcription::fake($responses),
                fn () => Transcription::of(base64_encode('audio'))->generate(),
                GeneratingTranscription::class,
                TranscriptionFailed::class,
            ],
            'reranking' => [
                fn ($responses) => RerankingOperation::fake($responses),
                fn () => RerankingOperation::of(['Hypervel is a framework'])->rerank('What is Hypervel?'),
                Reranking::class,
                RerankingFailed::class,
            ],
            'classification' => [
                fn ($responses) => Classification::fake($responses),
                fn () => Classification::of('Urgent request')->question('urgent', new Boolean('Is it urgent?'))->classify(),
                Classifying::class,
                ClassificationFailed::class,
            ],
        ];
    }

    public function testAThrowingSuccessListenerDoesNotReportTheOperationAsFailed(): void
    {
        Embeddings::fake();

        $failed = [];

        Event::listen(EmbeddingsFailed::class, function (EmbeddingsFailed $event) use (&$failed): void {
            $failed[] = $event;
        });

        $exception = new RuntimeException('Listener failed.');

        Event::listen(EmbeddingsGenerated::class, fn () => throw $exception);

        try {
            Embeddings::for(['Hello world'])->generate();
            $this->fail('The listener must throw.');
        } catch (RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame([], $failed);
    }

    public function testARecoveredFailoverReportsOnlyTheAttemptThatFailed(): void
    {
        Event::fake();

        $attempts = 0;

        Embeddings::fake(function () use (&$attempts): array {
            if ($attempts++ === 0) {
                throw RateLimitedException::forProvider('openai');
            }

            return [[0.1, 0.2]];
        });

        $response = Embeddings::for(['Hello world'])->generate(['openai', 'gemini']);

        [$failedAttempt, $recoveredAttempt] = Event::dispatched(GeneratingEmbeddings::class)
            ->map(fn (array $event): string => $event[0]->invocationId)
            ->all();

        $this->assertSame([[0.1, 0.2]], $response->embeddings);

        Event::assertDispatchedTimes(EmbeddingsFailed::class, 1);
        Event::assertDispatched(EmbeddingsFailed::class, fn (EmbeddingsFailed $event): bool => $event->invocationId === $failedAttempt);
        Event::assertDispatched(EmbeddingsGenerated::class, fn (EmbeddingsGenerated $event): bool => $event->invocationId === $recoveredAttempt);
    }
}
