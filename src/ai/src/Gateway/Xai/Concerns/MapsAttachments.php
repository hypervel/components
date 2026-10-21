<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Xai\Concerns;

use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\ProviderDocument;
use Hypervel\Ai\Files\RemoteDocument;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\StoredDocument;
use Hypervel\Ai\Files\StoredImage;
use Hypervel\Ai\Gateway\Concerns\ResolvesDocumentFilenames;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Storage;
use InvalidArgumentException;

trait MapsAttachments
{
    use ResolvesDocumentFilenames;

    /**
     * Map the given Hypervel attachments to xAI content parts.
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
                    'type' => 'input_image',
                    'image_url' => 'data:' . $attachment->mime . ';base64,' . $attachment->base64,
                ],
                $attachment instanceof RemoteImage => [
                    'type' => 'input_image',
                    'image_url' => $attachment->url,
                ],
                $attachment instanceof LocalImage => [
                    'type' => 'input_image',
                    'image_url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . base64_encode(file_get_contents($attachment->path)),
                ],
                $attachment instanceof StoredImage => [
                    'type' => 'input_image',
                    'image_url' => 'data:' . ($attachment->mimeType() ?? 'image/png') . ';base64,' . base64_encode(
                        (string) Storage::disk($attachment->disk)->get($attachment->path)
                    ),
                ],
                $attachment instanceof ProviderDocument => array_filter([
                    'type' => 'input_file',
                    'file_id' => $attachment->id,
                    'filename' => $attachment->name(),
                ]),
                $attachment instanceof Base64Document => [
                    'type' => 'input_file',
                    'file_data' => 'data:' . $attachment->mime . ';base64,' . $attachment->base64,
                    'filename' => $attachment->name() ?? $this->fallbackFilename($attachment->mime),
                ],
                $attachment instanceof LocalDocument => [
                    'type' => 'input_file',
                    'file_data' => 'data:' . ($attachment->mimeType() ?? 'application/octet-stream') . ';base64,' . base64_encode(file_get_contents($attachment->path)),
                    'filename' => $attachment->name(),
                ],
                $attachment instanceof RemoteDocument => array_filter([
                    'type' => 'input_file',
                    'file_url' => $attachment->url,
                    'filename' => $attachment->name(),
                ]),
                $attachment instanceof StoredDocument => [
                    'type' => 'input_file',
                    'file_data' => 'data:' . ($attachment->mimeType() ?? 'application/octet-stream') . ';base64,' . base64_encode(
                        (string) Storage::disk($attachment->disk)->get($attachment->path)
                    ),
                    'filename' => $attachment->name(),
                ],
                $attachment instanceof UploadedFile && $this->isImage($attachment) => [
                    'type' => 'input_image',
                    'image_url' => 'data:' . $attachment->getClientMimeType() . ';base64,' . base64_encode($attachment->get()),
                ],
                $attachment instanceof UploadedFile => [
                    'type' => 'input_file',
                    'file_data' => 'data:' . $attachment->getClientMimeType() . ';base64,' . base64_encode($attachment->get()),
                    'filename' => $attachment->getClientOriginalName(),
                ],
                default => throw new InvalidArgumentException('Unsupported attachment type [' . $attachment::class . ']'),
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
