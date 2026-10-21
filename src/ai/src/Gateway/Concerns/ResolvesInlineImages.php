<?php

declare(strict_types=1);

namespace Hypervel\Ai\Gateway\Concerns;

use finfo;
use Hypervel\Ai\Contracts\Files\StorableFile;
use Hypervel\Ai\Files\File;
use Hypervel\Ai\Files\Image;
use Hypervel\Http\UploadedFile;
use Hypervel\Support\Arr;
use InvalidArgumentException;

trait ResolvesInlineImages
{
    /**
     * Resolve an attachment to an image with its content and MIME type.
     *
     * @param array<string, string> $formats
     * @return array{Image&StorableFile, string, string}
     *
     * @throws InvalidArgumentException if the attachment is not an image with inline content in one of the formats
     */
    protected function resolveInlineImage(File|UploadedFile $attachment, string $endpoint, array $formats): array
    {
        $image = $attachment instanceof UploadedFile ? Image::fromUpload($attachment) : $attachment;

        if (! $image instanceof Image || ! $image instanceof StorableFile) {
            throw new InvalidArgumentException("{$endpoint} only accept images with inline content; [" . get_debug_type($attachment) . '] given.');
        }

        $content = $image->content();

        $mime = $image->mimeType() ?? (new finfo(FILEINFO_MIME_TYPE))->buffer($content);

        if (! array_key_exists($mime, $formats)) {
            throw new InvalidArgumentException("{$endpoint} only accept " . Arr::join($formats, ', ', count($formats) > 2 ? ', or ' : ' or ') . " images; [{$mime}] given.");
        }

        return [$image, $content, $mime];
    }
}
