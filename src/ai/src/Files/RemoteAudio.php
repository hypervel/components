<?php

declare(strict_types=1);

namespace Hypervel\Ai\Files;

use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Contracts\Files\TranscribableAudio;
use Hypervel\Ai\Files\Concerns\CanBeUploadedToProvider;
use Hypervel\Ai\Files\Concerns\HasRemoteContent;
use Hypervel\Ai\PendingResponses\PendingTranscriptionGeneration;
use Hypervel\Ai\Transcription;
use Hypervel\Contracts\Support\Arrayable;
use InvalidArgumentException;
use JsonSerializable;

class RemoteAudio extends Audio implements Arrayable, JsonSerializable, StorableFile, TranscribableAudio
{
    use CanBeUploadedToProvider;
    use HasRemoteContent;

    /**
     * Create a new remote audio file.
     */
    public function __construct(public string $url, ?string $mimeType = null)
    {
        if (blank($url)) {
            throw new InvalidArgumentException('Remote audio URL cannot be empty.');
        }

        $this->mime = $mimeType;
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
            'type' => 'remote-audio',
            'name' => $this->name(),
            'url' => $this->url,
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
}
