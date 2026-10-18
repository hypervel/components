<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Features\Classification;

use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Responses\Data\Answer;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Ai\Responses\Data\ScoreAnswer;
use Hypervel\Sentry\Features\Ai\AiDataSanitizer;

/**
 * @internal
 */
class ClassificationMessageFormatter
{
    /**
     * Format classification inputs within the structured-field budget.
     *
     * @param array<string, mixed>|string $state
     * @param array<string, Question> $questions
     */
    public static function formatInputMessages(string|array $state, array $questions): ?string
    {
        if (is_string($state)) {
            $state = AiDataSanitizer::truncateContentString(AiDataSanitizer::redactBinaryInString($state));
        }

        $formattedQuestions = array_map(function (Question $question): array {
            return self::formatQuestion($question);
        }, $questions);

        return self::encode([
            'type' => 'evaluation',
            'state' => $state,
            'questions' => (object) $formattedQuestions,
        ]);
    }

    /**
     * Format classification answers within the structured-field budget.
     *
     * @param array<string, Answer> $answers
     */
    public static function formatOutputMessages(array $answers): ?string
    {
        $formattedAnswers = [];
        foreach ($answers as $key => $answer) {
            $formattedAnswers[$key] = self::formatAnswer($answer);
        }

        return self::encode([
            'type' => 'evaluation',
            'answers' => (object) $formattedAnswers,
        ]);
    }

    /**
     * Describe the question using Sentry's evaluation types.
     */
    private static function formatQuestion(Question $question): array
    {
        if ($question instanceof Boolean) {
            $formatted = ['type' => 'noul', 'instructions' => $question->instructions];

            if ($question->criteria !== null) {
                $formatted['criteria'] = (object) $question->criteria;
            }

            return $formatted;
        }

        if ($question instanceof Choice) {
            // We cast the options to an object (aka stdClass) to force it to become an object in the shape of
            // {"0": "...", "1": "..."} . An array would be serialized as ["...", "...", ...] losing the index
            return ['type' => 'choice', 'instructions' => $question->instructions, 'criteria' => (object) $question->options];
        }

        if ($question instanceof Score) {
            return ['type' => 'score', 'instructions' => $question->instructions, 'criteria' => $question->levels];
        }

        return $question->toArray();
    }

    /**
     * Describe the answer using Sentry's evaluation types.
     */
    private static function formatAnswer(Answer $answer): array
    {
        if ($answer instanceof BooleanAnswer) {
            return ['type' => 'noul', 'noul' => $answer->probability];
        }

        if ($answer instanceof ChoiceAnswer) {
            return self::withConfidence([
                'type' => 'choice',
                'choice' => $answer->choice,
                'probabilities' => (object) $answer->probabilities,
            ], $answer->confidence);
        }

        if ($answer instanceof ScoreAnswer) {
            return self::withConfidence([
                'type' => 'score',
                'score' => $answer->score,
                'probabilities' => (object) $answer->probabilities,
                'legend' => (object) $answer->legend,
            ], $answer->confidence);
        }

        return $answer->toArray();
    }

    /**
     * Include confidence when the provider reports it.
     */
    private static function withConfidence(array $formatted, ?float $confidence): array
    {
        if ($confidence !== null) {
            $formatted['confidence'] = $confidence;
        }

        return $formatted;
    }

    /**
     * Encode a complete evaluation message without truncating JSON.
     */
    private static function encode(array $message): ?string
    {
        return AiDataSanitizer::encode([$message]);
    }
}
