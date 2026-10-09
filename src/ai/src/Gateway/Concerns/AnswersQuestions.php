<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Concerns;

use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Contracts\Providers\ClassificationProvider;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Ai\Responses\Data\Answer;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\ScoreAnswer;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Http\Client\Response;
use Hypervel\Http\UploadedFile;

trait AnswersQuestions
{
    /**
     * Get the path of the endpoint that answers questions.
     */
    abstract protected function classificationEndpoint(): string;

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
        $response = $this->withErrorHandling(
            $provider->name(),
            fn (): Response => $this->client($provider, $timeout)->post($this->classificationEndpoint(), array_merge($providerOptions, [
                'model' => $model,
                'state' => $state,
                'questions' => array_map($this->mapQuestion(...), $questions),
            ])),
        );

        $data = $response->json();

        $answers = [];

        foreach ($data['answers'] ?? [] as $key => $answer) {
            if ($mapped = $this->mapAnswer($answer, $questions[$key] ?? null)) {
                $answers[$key] = $mapped;
            }
        }

        return new ClassificationResponse(
            $answers,
            new TextUsage(
                inputTokens: $data['usage']['input_tokens'] ?? 0,
                outputTokens: $data['usage']['output_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $this->answeringModel($data, $model)),
        );
    }

    /**
     * Get the name of the model that answered the questions.
     */
    protected function answeringModel(array $data, string $model): string
    {
        return $data['model'] ?? $model;
    }

    /**
     * Map a question to the decisions wire format.
     */
    protected function mapQuestion(Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => array_filter([
                'type' => 'noul',
                'instructions' => $question->instructions,
                'criteria' => $question->criteria,
            ], fn (mixed $value): bool => $value !== null),
            $question instanceof Choice => [
                'type' => 'choice',
                'instructions' => $question->instructions,
                'criteria' => $question->options,
            ],
            $question instanceof Score => [
                'type' => 'score',
                'instructions' => $question->instructions,
                'criteria' => $question->levels,
            ],
            default => $question->toArray(),
        };
    }

    /**
     * Map an answer to an answer object, skipping unknown answer types.
     */
    protected function mapAnswer(array $answer, ?Question $question = null): ?Answer
    {
        return match ($answer['type'] ?? null) {
            'noul' => new BooleanAnswer($answer['noul']),
            'choice' => new ChoiceAnswer($answer['choice'], $answer['probabilities'] ?? [], $answer['confidence'] ?? null),
            'score' => new ScoreAnswer(
                $answer['score'],
                $this->withIntegerKeys($answer['probabilities'] ?? []),
                $this->withIntegerKeys($answer['legend'] ?? ($question instanceof Score ? $question->levels : [])),
                $answer['confidence'] ?? null,
            ),
            default => null,
        };
    }

    /**
     * Cast the string level keys the provider returns to integers.
     */
    protected function withIntegerKeys(array $levels): array
    {
        $result = [];

        foreach ($levels as $level => $value) {
            $result[(int) $level] = $value;
        }

        ksort($result);

        return $result;
    }
}
