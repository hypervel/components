<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Ollama\Concerns;

use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\StoredImage;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Storage;
use InvalidArgumentException;

trait MapsAttachments
{
    /**
     * Map the given Hypervel attachments to Ollama base64 image strings.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(function (mixed $attachment): string {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type [' . $attachment::class . ']'
                );
            }

            return match (true) {
                $attachment instanceof Base64Image => $attachment->base64,
                $attachment instanceof LocalImage => base64_encode(file_get_contents($attachment->path)),
                $attachment instanceof StoredImage => base64_encode(
                    (string) Storage::disk($attachment->disk)->get($attachment->path)
                ),
                $attachment instanceof UploadedFile && $this->isImage($attachment) => base64_encode($attachment->get()),
                $attachment instanceof RemoteImage => throw new InvalidArgumentException('Ollama does not support remote image URLs. Use a local or base64 image instead.'),
                default => throw new InvalidArgumentException('Ollama does not support document attachments. Only image attachments are supported.'),
            };
        })->all();
    }

    /**
     * Determine if the given uploaded file is an image.
     */
    protected function isImage(UploadedFile $attachment): bool
    {
        return in_array(
            $attachment->getClientMimeType(),
            [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
            ],
            true
        );
    }
}
