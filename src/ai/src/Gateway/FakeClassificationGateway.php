<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway;

use Closure;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Contracts\Gateway\ClassificationGateway;
use Hypervel\Ai\Contracts\Providers\ClassificationProvider;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Prompts\ClassificationPrompt;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Ai\Responses\Data\Answer;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\ScoreAnswer;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Http\UploadedFile;
use RuntimeException;

class FakeClassificationGateway implements ClassificationGateway
{
    protected int $currentResponseIndex = 0;

    protected bool $preventStrayClassifications = false;

    /**
     * Create a classification gateway with fake responses.
     */
    public function __construct(
        protected Closure|array $responses = [],
    ) {
    }

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
    ): ClassificationResponse {
        $prompt = new ClassificationPrompt($state, $questions, $provider, $model, $timeout, $providerOptions, $attachments);

        return $this->nextResponse($provider, $model, $prompt);
    }

    /**
     * Get the next response instance.
     */
    protected function nextResponse(
        ClassificationProvider $provider,
        string $model,
        ClassificationPrompt $prompt
    ): ClassificationResponse {
        // Reserve the entry first; callbacks may yield or throw.
        $index = $this->currentResponseIndex++;

        $response = is_array($this->responses)
            ? ($this->responses[$index] ?? null)
            : call_user_func($this->responses, $prompt);

        return $this->marshalResponse(
            $response,
            $provider,
            $model,
            $prompt
        );
    }

    /**
     * Marshal the given response into a full response instance.
     */
    protected function marshalResponse(
        mixed $response,
        ClassificationProvider $provider,
        string $model,
        ClassificationPrompt $prompt
    ): ClassificationResponse {
        if ($response instanceof Closure) {
            $response = $response($prompt);
        }

        if (is_null($response)) {
            if ($this->preventStrayClassifications) {
                throw new RuntimeException('Attempted classification without a fake response.');
            }

            $response = [];
        }

        if ($response instanceof ClassificationResponse) {
            return $response;
        }

        $answers = array_map(
            fn (Question $question, string $key): mixed => $response[$key] ?? $this->generateFakeAnswer($question),
            $prompt->questions,
            array_keys($prompt->questions),
        );

        return new ClassificationResponse(
            array_combine(array_keys($prompt->questions), $answers),
            new TextUsage,
            new Meta($provider->name(), $model),
        );
    }

    /**
     * Generate a shape-valid fake answer for the given question.
     *
     * @throws RuntimeException if a custom question has no supplied fake answer
     */
    protected function generateFakeAnswer(Question $question): Answer
    {
        return match (true) {
            $question instanceof Boolean => new BooleanAnswer(round(mt_rand() / mt_getrandmax(), 3)),
            $question instanceof Choice => $this->fakeChoiceAnswer(array_keys($question->options)),
            $question instanceof Score => $this->fakeScoreAnswer($question->levels),
            default => throw new RuntimeException('Unable to generate a fake answer for question [' . $question::class . ']. Provide a fake answer instead.'),
        };
    }

    /**
     * Generate a fake choice answer.
     *
     * @param list<string> $options
     */
    protected function fakeChoiceAnswer(array $options): ChoiceAnswer
    {
        $probabilities = $this->randomDistribution($options);

        return new ChoiceAnswer(
            array_search(max($probabilities), $probabilities, true),
            $probabilities,
            round(max($probabilities), 3),
        );
    }

    /**
     * Generate a fake score answer.
     *
     * @param list<array<string, mixed>|string> $levels
     */
    protected function fakeScoreAnswer(array $levels): ScoreAnswer
    {
        $probabilities = $this->randomDistribution(array_keys($levels));

        $score = array_sum(array_map(fn (int $level, float $probability): float => $level * $probability, array_keys($probabilities), $probabilities));

        return new ScoreAnswer(round($score, 3), $probabilities, $levels, round(max($probabilities), 3));
    }

    /**
     * Generate random probabilities summing to one over the given keys.
     *
     * @param list<int|string> $keys
     * @return array<int|string, float>
     */
    protected function randomDistribution(array $keys): array
    {
        $weights = array_map(fn (): int => mt_rand(1, 100), $keys);

        $total = array_sum($weights);

        return array_combine($keys, array_map(fn (int $weight): float => round($weight / $total, 3), $weights));
    }

    /**
     * Indicate that an exception should be thrown if any classification is not faked.
     *
     * Tests only. This setting affects the fake gateway shared by requests in the worker.
     */
    public function preventStrayClassifications(bool $prevent = true): self
    {
        $this->preventStrayClassifications = $prevent;

        return $this;
    }
}
