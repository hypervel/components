<?php

declare(strict_types=1);

namespace Hypervel\Http\Testing;

use Hypervel\Http\UploadedFile;
use Hypervel\Support\MimeTypeExtensionGuesser;

class File extends UploadedFile
{
    /**
     * The "size" to report.
     */
    public int $sizeToReport = 0;

    /**
     * The MIME type to report.
     */
    public ?string $mimeTypeToReport = null;

    /**
     * Create a new file instance.
     *
     * @param resource $tempFile
     */
    public function __construct(
        public string $name,
        public $tempFile
    ) {
        $this->tempFile = $tempFile;

        parent::__construct(
            $this->tempFilePath(),
            $name,
            $this->getMimeType(),
            UPLOAD_ERR_OK,
            null,
            true
        );
    }

    /**
     * Create a new fake file.
     */
    public static function create(string $name, int $kilobytes = 0): File
    {
        return (new FileFactory())->create($name, $kilobytes);
    }

    /**
     * Create a new fake file with content.
     */
    public static function createWithContent(string $name, string $content): File
    {
        return (new FileFactory())->createWithContent($name, $content);
    }

    /**
     * Create a new fake image.
     */
    public static function image(string $name, int $width = 10, int $height = 10): File
    {
        return (new FileFactory())->image($name, $width, $height);
    }

    /**
     * Set the "size" of the file in kilobytes.
     */
    public function size(int $kilobytes): static
    {
        $this->sizeToReport = $kilobytes * 1024;

        return $this;
    }

    /**
     * Get the size of the file.
     */
    public function getSize(): int
    {
        return $this->sizeToReport ?: parent::getSize();
    }

    /**
     * Set the "MIME type" for the file.
     */
    public function mimeType(string $mimeType): static
    {
        $this->mimeTypeToReport = $mimeType;

        return $this;
    }

    /**
     * Get the MIME type of the file.
     */
    public function getMimeType(): string
    {
        if ($this->mimeTypeToReport) {
            return $this->mimeTypeToReport;
        }

        return (new MimeTypeExtensionGuesser())->guessMimeType(
            pathinfo($this->name, PATHINFO_EXTENSION)
        ) ?: 'application/octet-stream';
    }

    /**
     * Get the path to the temporary file.
     */
    protected function tempFilePath(): string
    {
        return stream_get_meta_data($this->tempFile)['uri'];
    }
}
