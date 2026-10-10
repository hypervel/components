<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\OpenAi;

use finfo;
use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Gateway\ClassificationGateway;
use Hypervel\Ai\Contracts\Providers\ClassificationProvider;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Gateway\Concerns\HandlesFailoverErrors;
use Hypervel\Ai\Gateway\OpenAi\Concerns\CreatesOpenAiClient;
use Hypervel\Ai\Providers\Provider;
use Hypervel\Ai\Responses\ClassificationResponse;
use Hypervel\Ai\Responses\Data\Answer;
use Hypervel\Ai\Responses\Data\BooleanAnswer;
use Hypervel\Ai\Responses\Data\ChoiceAnswer;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\ScoreAnswer;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Http\UploadedFile;
use InvalidArgumentException;

class OpenAiClassificationGateway implements ClassificationGateway
{
    use CreatesOpenAiClient;
    use HandlesFailoverErrors;

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
        /** @var ClassificationProvider&Provider $provider */
        $input = $this->mapInput($state, $attachments);

        $response = $this->withErrorHandling(
            $provider->name(),
            fn () => $this->client($provider, $timeout)->post('decisions', array_merge($providerOptions, [
                'model' => $model,
                'input' => $input,
                'questions' => array_map($this->mapQuestion(...), array_keys($questions), $questions),
            ])),
        );

        $data = $response->json();

        $answers = [];

        foreach ($data['answers'] ?? [] as $answer) {
            $key = $answer['name'] ?? null;

            if (is_string($key) && $mapped = $this->mapAnswer($answer, $questions[$key] ?? null)) {
                $answers[$key] = $mapped;
            }
        }

        return new ClassificationResponse(
            $answers,
            new TextUsage(
                inputTokens: $data['usage']['input_tokens'] ?? 0,
                outputTokens: $data['usage']['output_tokens'] ?? 0,
            ),
            new Meta($provider->name(), $data['model'] ?? $model),
        );
    }

    /**
     * Map the state and attachments to the decisions input.
     *
     * @param array<string, mixed>|string $state
     * @param array<int, File|UploadedFile> $attachments
     */
    protected function mapInput(string|array $state, array $attachments): string|array
    {
        $text = $this->toText($state);

        if ($attachments === []) {
            return $text;
        }

        return [[
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => $text],
                ...array_map($this->mapImage(...), array_values($attachments)),
            ],
        ]];
    }

    /**
     * Map an image to an input image part, which the decisions endpoint only accepts as an inline data URL.
     *
     * @throws InvalidArgumentException if the attachment is not a JPEG, PNG, GIF, or WebP image with inline content
     */
    protected function mapImage(File|UploadedFile $image): array
    {
        if ($image instanceof UploadedFile) {
            $image = Image::fromUpload($image);
        }

        if (! $image instanceof Image || ! $image instanceof StorableFile) {
            throw new InvalidArgumentException('OpenAI decisions only accept images with inline content; [' . get_debug_type($image) . '] given.');
        }

        $content = $image->content();

        $mime = $image->mimeType() ?? (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            throw new InvalidArgumentException("OpenAI decisions only accept JPEG, PNG, GIF, or WebP images; [{$mime}] given.");
        }

        return array_merge($image->providerOptions(Lab::OpenAI), [
            'type' => 'input_image',
            'image_url' => 'data:' . $mime . ';base64,' . base64_encode($content),
        ]);
    }

    /**
     * Map a question to the OpenAI decisions wire format.
     */
    protected function mapQuestion(string $name, Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => [
                'type' => 'predicate',
                'name' => $name,
                'instructions' => $this->predicateInstructions($question),
            ],
            $question instanceof Choice => [
                'type' => 'choice',
                'name' => $name,
                'instructions' => $this->toText($question->instructions),
                'choices' => array_map(fn (string $value, string|array|null $description): array => array_filter([
                    'value' => $value,
                    'description' => is_null($description) ? null : $this->toText($description),
                ], fn (mixed $value): bool => $value !== null), array_keys($question->options), $question->options),
            ],
            $question instanceof Score => [
                'type' => 'score',
                'name' => $name,
                'instructions' => $this->toText($question->instructions),
                'levels' => array_map($this->mapLevel(...), $question->levels),
            ],
            default => ['name' => $name, ...$question->toArray()],
        };
    }

    /**
     * Map a score level to a label, keeping the description of levels given as a label and description.
     *
     * @param array<string, mixed>|string $level
     */
    protected function mapLevel(string|array $level): array
    {
        if (is_string($level) || ! is_string($level['label'] ?? null)) {
            return ['label' => $this->toText($level)];
        }

        return array_filter([
            'label' => $level['label'],
            'description' => isset($level['description']) ? $this->toText($level['description']) : null,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Get the predicate instructions, folding in the true and false criteria the endpoint has no field for.
     */
    protected function predicateInstructions(Boolean $question): string
    {
        $instructions = $this->toText($question->instructions);

        foreach ($question->criteria ?? [] as $case => $description) {
            $instructions .= "\n\n" . ucfirst($case) . ": {$description}";
        }

        return $instructions;
    }

    /**
     * Map an answer to an answer object, skipping refusals and unknown answer types.
     */
    protected function mapAnswer(array $answer, ?Question $question): ?Answer
    {
        $probabilities = array_column($answer['probabilities'] ?? [], 'probability', 'value');

        return match ($answer['type'] ?? null) {
            'predicate' => new BooleanAnswer($answer['probability']),
            'choice' => new ChoiceAnswer((string) $answer['choice'], $probabilities, $answer['confidence'] ?? null),
            'score' => new ScoreAnswer(
                $answer['score'],
                $probabilities,
                $question instanceof Score ? $question->levels : array_column($answer['probabilities'] ?? [], 'label', 'value'),
                $answer['confidence'] ?? null,
            ),
            default => null,
        };
    }

    /**
     * Convert structured content to the text the endpoint accepts.
     *
     * @param array<string, mixed>|string $value
     */
    protected function toText(string|array $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
