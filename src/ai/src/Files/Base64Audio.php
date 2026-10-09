<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Hypervel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Http\UploadedFile;
use InvalidArgumentException;
use JsonSerializable;
use Override;

class Base64Audio extends Audio implements Arrayable, JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;

    /**
     * Create a new Base64 audio file.
     */
    public function __construct(public string $base64, ?string $mimeType = null)
    {
        if (blank($base64)) {
            throw new InvalidArgumentException('Base64 audio content cannot be empty.');
        }

        $this->mime = $mimeType;
    }

    /**
     * Create a new instance from an uploaded file.
     */
    #[Override]
    public static function fromUpload(UploadedFile $file, ?string $mimeType = null): self
    {
        return new self(
            base64_encode($file->getContent()),
            mimeType: $mimeType ?? $file->getClientMimeType(),
        );
    }

    /**
     * Get the raw representation of the file.
     */
    public function content(): string
    {
        return base64_decode($this->base64);
    }

    /**
     * Generate a transcription of the given audio.
     */
    public function transcription(): PendingTranscriptionGeneration
    {
        return Transcription::of($this);
    }

    /**
     * Get the instance as an array.
     */
    public function toArray(): array
    {
        return [
            'type' => 'base64-audio',
            'name' => $this->name(),
            'base64' => $this->base64,
            'mime' => $this->mime,
        ];
    }

    /**
     * Get the JSON serializable representation of the instance.
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Get the file content as a string.
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
