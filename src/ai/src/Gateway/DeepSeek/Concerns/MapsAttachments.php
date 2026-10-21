<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\DeepSeek\Concerns;

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
     * Map the given Hypervel attachments to Chat Completions content parts.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        return $attachments->map(function (mixed $attachment): array {
            if (! $attachment instanceof File && ! $attachment instanceof UploadedFile) {
                throw new InvalidArgumentException(
                    'Unsupported attachment type [' . $attachment::class . ']'
                );
            }

            return match (true) {
                $attachment instanceof Base64Image => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:' . $attachment->mime . ';base64,' . $attachment->base64],
                ],
                $attachment instanceof RemoteImage => [
                    'type' => 'image_url',
                    'image_url' => ['url' => $attachment->url],
                ],
                $attachment instanceof LocalImage => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . base64_encode(file_get_contents($attachment->path))],
                ],
                $attachment instanceof StoredImage => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . base64_encode(
                        (string) Storage::disk($attachment->disk)->get($attachment->path)
                    )],
                ],
                $attachment instanceof UploadedFile && $this->isImage($attachment) => [
                    'type' => 'image_url',
                    'image_url' => ['url' => 'data:' . $attachment->getClientMimeType() . ';base64,' . base64_encode($attachment->get())],
                ],
                default => throw new InvalidArgumentException('DeepSeek does not support document attachments. Only image attachments are supported.'),
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
