<?php

declare(strict_types=1);

namespace Hypervel\Http\Testing;

use Hypervel\Http\UploadedFile;

class File extends UploadedFile
{
    /**
     * The name of the file.
     */
    public string $name;

    /**
     * The temporary file resource.
     *
     * @var resource
     */
    public mixed $tempFile;

    /**
     * The "size" to report.
     */
    public ?int $sizeToReport = null;

    /**
     * The MIME type to report.
     */
    public ?string $mimeTypeToReport = null;

    /**
     * Create a new file instance.
     *
     * @param resource $tempFile
     */
    public function __construct(string $name, mixed $tempFile)
    {
        $this->name = $name;
        $this->tempFile = $tempFile;

        parent::__construct(
            $this->tempFilePath(),
            $name,
            $this->getMimeType(),
            null,
            true
        );
    }

    /**
     * Create a new fake file.
     */
    public static function create(string $name, string|int $kilobytes = 0): self
    {
        return (new FileFactory())->create($name, $kilobytes);
    }

    /**
     * Create a new fake file with content.
     */
    public static function createWithContent(string $name, string $content): self
    {
        return (new FileFactory())->createWithContent($name, $content);
    }

    /**
     * Create a new fake image.
     */
    public static function image(string $name, int $width = 10, int $height = 10): self
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
     * Set the MIME type for the file.
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
        return $this->mimeTypeToReport ?: MimeType::from($this->name);
    }

    /**
     * Get the path to the temporary file.
     */
    protected function tempFilePath(): string
    {
        return stream_get_meta_data($this->tempFile)['uri'];
    }
}
