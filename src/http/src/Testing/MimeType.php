<?php

declare(strict_types=1);

namespace Hypervel\Http\Testing;

use Symfony\Component\Mime\MimeTypes;

class MimeType
{
    /**
     * The MIME types instance.
     */
    private static ?MimeTypes $mime = null;

    /**
     * Get the MIME types instance.
     */
    public static function getMimeTypes(): MimeTypes
    {
        if (self::$mime === null) {
            self::$mime = new MimeTypes;
        }

        return self::$mime;
    }

    /**
     * Get the MIME type for a file based on the file's extension.
     */
    public static function from(string $filename): string
    {
        $extension = pathinfo($filename, PATHINFO_EXTENSION);

        return self::get($extension);
    }

    /**
     * Get the MIME type for a given extension or return all MIME types.
     */
    public static function get(string $extension): string
    {
        return array_first(self::getMimeTypes()->getMimeTypes($extension)) ?? 'application/octet-stream';
    }

    /**
     * Search for the extension of a given MIME type.
     */
    public static function search(string $mimeType): ?string
    {
        return array_first(self::getMimeTypes()->getExtensions($mimeType));
    }
}
