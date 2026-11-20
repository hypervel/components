<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Gemini;

use Hypervel\Ai\Classification\Boolean;
use Hypervel\Ai\Classification\Choice;
use Hypervel\Ai\Classification\Score;
use Hypervel\Ai\Contracts\Gateway\ClassificationGateway;
use Hypervel\Ai\Contracts\Question;
use Hypervel\Ai\Enums\Lab;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Gateway\Concerns\AnswersQuestions;
use Hypervel\Ai\Gateway\Concerns\ResolvesInlineImages;
use Hypervel\Ai\Responses\Data\TextUsage;
use Hypervel\Http\UploadedFile;
use InvalidArgumentException;

class GeminiClassificationGateway implements ClassificationGateway
{
    use AnswersQuestions;
    use Concerns\CreatesGeminiClient;
    use Concerns\HandlesGeminiErrors;
    use ResolvesInlineImages;

    /**
     * Build the request payload for the given questions about the state.
     *
     * @param array<array-key, mixed>|string $state
     * @param array<string, Question> $questions
     * @param array<int|string, File|UploadedFile> $attachments
     */
    protected function payload(string $model, string|array $state, array $questions, array $attachments): array
    {
        return [
            'model' => $model,
            'input' => $this->mapInput($state, $attachments),
            // Numeric-string names become integer keys, so the cast keeps the questions a JSON object.
            'questions' => (object) array_map($this->mapQuestion(...), $questions),
        ];
    }

    /**
     * Map the state and attachments to the decisions input.
     *
     * @param array<array-key, mixed>|string $state
     * @param array<int|string, File|UploadedFile> $attachments
     */
    protected function mapInput(string|array $state, array $attachments): string|array
    {
        $text = $this->toText($state);

        if ($attachments === []) {
            return $text;
        }

        $content = [['type' => 'text', 'text' => $text]];

        foreach ($attachments as $name => $attachment) {
            if (is_string($name)) {
                $content[] = ['type' => 'text', 'text' => "{$name}:"];
            }

            $content[] = $this->mapImage($attachment);
        }

        return $content;
    }

    /**
     * Map an image to an image content block, referencing uploaded files by URI.
     *
     * @throws InvalidArgumentException if the attachment is not an uploaded file or a JPEG, PNG, GIF, WebP, HEIC, HEIF, BMP, or TIFF image with inline content
     */
    protected function mapImage(File|UploadedFile $attachment): array
    {
        if ($attachment instanceof ProviderImage) {
            return array_merge($attachment->providerOptions(Lab::Gemini), [
                'type' => 'image',
                'uri' => $attachment->id,
            ]);
        }

        [$image, $content, $mime] = $this->resolveInlineImage($attachment, 'Gemini decisions', [
            'image/jpeg' => 'JPEG', 'image/png' => 'PNG', 'image/gif' => 'GIF', 'image/webp' => 'WebP',
            'image/heic' => 'HEIC', 'image/heif' => 'HEIF', 'image/bmp' => 'BMP', 'image/tiff' => 'TIFF',
        ]);

        return array_merge($image->providerOptions(Lab::Gemini), [
            'type' => 'image',
            'data' => base64_encode($content),
            'mime_type' => $mime,
        ]);
    }

    /**
     * Map a question to the Gemini decisions wire format.
     */
    protected function mapQuestion(Question $question): array
    {
        return match (true) {
            $question instanceof Boolean => array_filter([
                'type' => 'noul',
                'instructions' => $this->toText($question->instructions),
                // Empty criteria would encode as a JSON list, so they are omitted like null criteria.
                'criteria' => $question->criteria ? array_filter([
                    'true_description' => isset($question->criteria['true']) ? $this->toText($question->criteria['true']) : null,
                    'false_description' => isset($question->criteria['false']) ? $this->toText($question->criteria['false']) : null,
                ], fn (?string $value): bool => $value !== null) : null,
            ], fn (mixed $value): bool => $value !== null),
            $question instanceof Choice => [
                'type' => 'choice',
                'instructions' => $this->toText($question->instructions),
                // Numeric-string labels become integer keys, so the cast keeps the criteria a JSON object.
                'criteria' => (object) array_map(fn (array|string|null $description): ?string => is_null($description) ? null : $this->toText($description), $question->options),
            ],
            $question instanceof Score => [
                'type' => 'score',
                'instructions' => $this->toText($question->instructions),
                'criteria' => array_map($this->toText(...), $question->levels),
            ],
            default => $question->toArray(),
        };
    }

    /**
     * Map the response usage to text usage.
     */
    protected function usage(array $data): TextUsage
    {
        return new TextUsage(
            inputTokens: $data['usage']['total_input_tokens'] ?? 0,
            outputTokens: $data['usage']['total_output_tokens'] ?? 0,
            cacheReadInputTokens: $data['usage']['total_cached_tokens'] ?? null,
        );
    }

    /**
     * Get the path of the endpoint that answers questions.
     */
    protected function classificationEndpoint(): string
    {
        return 'decisions';
    }

    /**
     * Convert structured content to the text the endpoint accepts.
     *
     * @param array<array-key, mixed>|string $value
     */
    protected function toText(string|array $value): string
    {
        return is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
