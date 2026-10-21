<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Gemini\Concerns;

use Hypervel\Ai\Files\Base64Audio;
use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\Base64Video;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Files\LocalAudio;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\LocalVideo;
use Hypervel\Ai\Files\ProviderDocument;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Files\RemoteAudio;
use Hypervel\Ai\Files\RemoteDocument;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\RemoteVideo;
use Hypervel\Ai\Files\StoredAudio;
use Hypervel\Ai\Files\StoredDocument;
use Hypervel\Ai\Files\StoredImage;
use Hypervel\Ai\Files\StoredVideo;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Storage;
use InvalidArgumentException;

trait MapsAttachments
{
    /**
     * Map the given Hypervel attachments to Gemini content blocks.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(fn (mixed $attachment): array => $this->mapAttachment($attachment))->all();
    }

    /**
     * Map a Hypervel attachment to a Gemini content block.
     */
    protected function mapAttachment(mixed $attachment): array
    {
        if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
            throw new InvalidArgumentException(
                'Unsupported attachment type [' . get_debug_type($attachment) . ']'
            );
        }

        return match (true) {
            $attachment instanceof ProviderImage => [
                'type' => 'image',
                'uri' => $attachment->id,
            ],
            $attachment instanceof Base64Image => [
                'type' => 'image',
                'mime_type' => $attachment->mime,
                'data' => $attachment->base64,
            ],
            $attachment instanceof RemoteImage => [
                'type' => 'image',
                'mime_type' => $attachment->mimeType() ?? 'image/png',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof LocalImage => [
                'type' => 'image',
                'mime_type' => $attachment->mimeType() ?? 'image/png',
                'data' => base64_encode(file_get_contents($attachment->path)),
            ],
            $attachment instanceof StoredImage => [
                'type' => 'image',
                'mime_type' => $attachment->mimeType() ?? 'image/png',
                'data' => base64_encode(
                    (string) Storage::disk($attachment->disk)->get($attachment->path)
                ),
            ],
            $attachment instanceof ProviderDocument => [
                'type' => 'document',
                'uri' => $attachment->id,
            ],
            $attachment instanceof Base64Document => [
                'type' => 'document',
                'mime_type' => $attachment->mime,
                'data' => $attachment->base64,
            ],
            $attachment instanceof LocalDocument => [
                'type' => 'document',
                'mime_type' => $attachment->mimeType() ?? 'application/octet-stream',
                'data' => base64_encode(file_get_contents($attachment->path)),
            ],
            $attachment instanceof RemoteDocument => [
                'type' => 'document',
                'mime_type' => $attachment->mimeType() ?? 'application/octet-stream',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof StoredDocument => [
                'type' => 'document',
                'mime_type' => $attachment->mimeType() ?? 'application/octet-stream',
                'data' => base64_encode(
                    (string) Storage::disk($attachment->disk)->get($attachment->path)
                ),
            ],
            $attachment instanceof Base64Audio => [
                'type' => 'audio',
                'mime_type' => $attachment->mime ?? 'audio/mp3',
                'data' => $attachment->base64,
            ],
            $attachment instanceof LocalAudio => [
                'type' => 'audio',
                'mime_type' => $attachment->mimeType() ?? 'audio/mp3',
                'data' => base64_encode(file_get_contents($attachment->path)),
            ],
            $attachment instanceof StoredAudio => [
                'type' => 'audio',
                'mime_type' => $attachment->mimeType() ?? 'audio/mp3',
                'data' => base64_encode(
                    (string) Storage::disk($attachment->disk)->get($attachment->path)
                ),
            ],
            $attachment instanceof RemoteAudio => [
                'type' => 'audio',
                'mime_type' => $attachment->mimeType() ?? 'audio/mp3',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof Base64Video => [
                'type' => 'video',
                'mime_type' => $attachment->mime ?? 'video/mp4',
                'data' => $attachment->base64,
            ],
            $attachment instanceof LocalVideo => [
                'type' => 'video',
                'mime_type' => $attachment->mimeType() ?? 'video/mp4',
                'data' => base64_encode(file_get_contents($attachment->path)),
            ],
            $attachment instanceof StoredVideo => [
                'type' => 'video',
                'mime_type' => $attachment->mimeType() ?? 'video/mp4',
                'data' => base64_encode(
                    (string) Storage::disk($attachment->disk)->get($attachment->path)
                ),
            ],
            $attachment instanceof RemoteVideo => $this->isYouTubeUrl($attachment->url) ? array_filter([
                'type' => 'video',
                'mime_type' => $attachment->mime,
                'uri' => $attachment->url,
            ]) : [
                'type' => 'video',
                'mime_type' => $attachment->mimeType() ?? 'video/mp4',
                'data' => base64_encode($attachment->content()),
            ],
            $attachment instanceof UploadedFile => [
                'type' => $this->contentTypeFor($attachment->getClientMimeType()),
                'mime_type' => $attachment->getClientMimeType(),
                'data' => base64_encode($attachment->get()),
            ],
            default => throw new InvalidArgumentException('Unsupported attachment type [' . get_debug_type($attachment) . ']'),
        };
    }

    /**
     * Resolve the Gemini content block type for the given MIME type.
     */
    protected function contentTypeFor(?string $mime): string
    {
        return match (strtok((string) $mime, '/')) {
            'image' => 'image',
            'audio' => 'audio',
            'video' => 'video',
            default => 'document',
        };
    }

    /**
     * Determine if the given URL is a YouTube URL, which Gemini accepts as a file URI.
     */
    protected function isYouTubeUrl(string $url): bool
    {
        return in_array(strtolower((string) parse_url($url, PHP_URL_HOST)), [
            'youtube.com', 'www.youtube.com', 'm.youtube.com', 'youtu.be', 'www.youtu.be',
        ], true);
    }
}
