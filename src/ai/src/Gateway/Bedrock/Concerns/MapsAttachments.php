<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Bedrock\Concerns;

use Hypervel\Ai\Files\Base64Document;
use Hypervel\Ai\Files\Base64Image;
use Hypervel\Ai\Files\Document;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Files\Image;
use Hypervel\Ai\Files\LocalDocument;
use Hypervel\Ai\Files\LocalImage;
use Hypervel\Ai\Files\ProviderDocument;
use Hypervel\Ai\Files\ProviderImage;
use Hypervel\Ai\Files\RemoteDocument;
use Hypervel\Ai\Files\RemoteImage;
use Hypervel\Ai\Files\S3Document;
use Hypervel\Ai\Files\StoredDocument;
use Hypervel\Ai\Files\StoredImage;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Storage;
use InvalidArgumentException;

trait MapsAttachments
{
    protected const int MAX_DOCUMENT_NAME_LENGTH = 200;

    /**
     * Map the given Hypervel attachments to Bedrock content blocks.
     */
    protected function mapAttachments(Collection $attachments): array
    {
        $names = [];

        return $attachments->map(function (File|UploadedFile $attachment) use (&$names): array {
            $block = match (true) {
                $attachment instanceof Base64Document,
                $attachment instanceof LocalDocument,
                $attachment instanceof S3Document,
                $attachment instanceof StoredDocument => $this->buildDocumentBlock($attachment),
                $attachment instanceof Base64Image => $this->buildImageBlock($attachment, $attachment->content()),
                $attachment instanceof LocalImage => $this->buildImageBlock($attachment, file_get_contents($attachment->path)),
                $attachment instanceof StoredImage => $this->buildImageBlock(
                    $attachment,
                    Storage::disk($attachment->disk)->get($attachment->path),
                ),
                $attachment instanceof RemoteDocument,
                $attachment instanceof RemoteImage => throw new InvalidArgumentException(
                    'Remote attachments are not supported by Bedrock; download the file and pass it as a Base64, Local, or Stored attachment.'
                ),
                $attachment instanceof ProviderDocument,
                $attachment instanceof ProviderImage => throw new InvalidArgumentException(
                    'Provider-stored attachments are not supported by Bedrock.'
                ),
                default => throw new InvalidArgumentException('Unsupported attachment type [' . $attachment::class . '].'),
            };

            if (isset($block['document'])) {
                $baseName = $name = $block['document']['name'];
                $number = 2;

                // Converse rejects duplicate names within one message, including sanitized collisions.
                while (isset($names[$name])) {
                    $suffix = ' (' . $number++ . ')';
                    $name = rtrim(substr($baseName, 0, self::MAX_DOCUMENT_NAME_LENGTH - strlen($suffix))) . $suffix;
                }

                $block['document']['name'] = $name;
                $names[$name] = true;
            }

            return $block;
        })->all();
    }

    /**
     * Build a Bedrock document content block.
     */
    protected function buildDocumentBlock(Document $document): array
    {
        $source = match (true) {
            $document instanceof S3Document => [
                's3Location' => array_filter([
                    'uri' => $document->url,
                    'bucketOwner' => $document->bucketOwner,
                ]),
            ],
            default => ['bytes' => $document->content()],
        };

        return [
            'document' => array_filter([
                'format' => $this->getDocumentFormat($document),
                'name' => $this->getDocumentName($document),
                'source' => $source,
            ]),
        ];
    }

    /**
     * Build a Bedrock image content block.
     */
    protected function buildImageBlock(Image $image, string $bytes): array
    {
        return [
            'image' => [
                'format' => $this->getImageFormat($image),
                'source' => [
                    'bytes' => $bytes,
                ],
            ],
        ];
    }

    /**
     * Map a Document's MIME type to a Bedrock document format.
     */
    protected function getDocumentFormat(Document $document): ?string
    {
        $mime = strtolower(trim((string) strtok($document->mimeType() ?? '', ';')));

        if ($mime === '' || $mime === '0') {
            return null;
        }

        return match ($mime) {
            'application/pdf' => 'pdf',
            'text/csv' => 'csv',
            'application/msword' => 'doc',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'application/vnd.ms-excel' => 'xls',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
            'text/html' => 'html',
            'text/markdown', 'text/x-markdown' => 'md',
            'text/plain' => 'txt',
            default => null,
        };
    }

    /**
     * Build a Bedrock-compliant document name.
     *
     * Bedrock limits names to 200 characters containing alphanumerics, whitespace,
     * hyphens, parentheses, and square brackets. mapAttachments resolves duplicates.
     */
    protected function getDocumentName(Document $document): string
    {
        $name = $document->name() ?? 'document';
        $name = pathinfo($name, PATHINFO_FILENAME) ?: $name;
        $name = preg_replace('/[^A-Za-z0-9\-\(\)\[\] ]+/', '-', $name);

        return trim(substr((string) preg_replace('/\s+/', ' ', (string) $name), 0, self::MAX_DOCUMENT_NAME_LENGTH)) ?: 'document';
    }

    /**
     * Map an Image's MIME type to a Bedrock image format.
     *
     * Bedrock supports JPEG, PNG, GIF, and WebP images.
     *
     * @throws InvalidArgumentException if the MIME type cannot be determined or is unsupported
     */
    protected function getImageFormat(Image $image): string
    {
        $mime = $image->mimeType();

        if (! $mime) {
            throw new InvalidArgumentException('Unable to determine MIME type for image [' . $image->name() . '].');
        }

        return match (strtolower(trim($mime))) {
            'image/jpeg', 'image/jpg' => 'jpeg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            default => throw new InvalidArgumentException('Unsupported image MIME type [' . $mime . '].'),
        };
    }
}
