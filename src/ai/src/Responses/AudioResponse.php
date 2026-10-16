<?php

declare(strict_types=1);

namespace Hypervel\Ai\Responses;

use Hypervel\Ai\Concerns\Storable;
use Hypervel\Ai\Responses\Data\Meta;
use Hypervel\Ai\Responses\Data\Usage;
use Hypervel\Support\Str;
use Stringable;

class AudioResponse implements Stringable
{
    use Storable;

    public ?string $mime = null;

    /**
     * Create an audio response.
     *
     * @param string $audio the Base64 representation of the audio
     */
    public function __construct(
        public string $audio,
        public Usage $usage,
        public Meta $meta,
        ?string $mimeType = null,
    ) {
        $this->mime = $mimeType;
    }

    /**
     * Get a default filename for the file.
     */
    protected function randomStorageName(): string
    {
        return $this->randomStorageName ??= Str::random(40) . match ($this->mime) {
            'audio/wav', 'audio/x-wav' => '.wav',
            'audio/opus' => '.opus',
            'audio/pcm' => '.pcm',
            'audio/ulaw' => '.ulaw',
            'audio/alaw' => '.alaw',
            default => '.mp3',
        };
    }

    /**
     * Get the raw representation of the audio.
     */
    public function content(): string
    {
        return base64_decode($this->audio);
    }

    /**
     * Get the MIME type for the audio.
     */
    public function mimeType(): ?string
    {
        return $this->mime;
    }

    /**
     * Get the raw string content of the audio.
     */
    public function __toString(): string
    {
        return $this->content();
    }
}
