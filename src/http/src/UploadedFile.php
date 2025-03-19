<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hyperf\Collection\Arr;
use Hyperf\Context\ApplicationContext;
use Hyperf\HttpMessage\Stream\StandardStream;
use Hyperf\HttpMessage\Upload\UploadedFile as HyperfUploadedFile;
use Hyperf\Macroable\Macroable;
use Hyperf\Stringable\Str;
use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Http\Exceptions\CannotWriteFileException;
use Hypervel\Http\Exceptions\ExtensionFileException;
use Hypervel\Http\Exceptions\FileException;
use Hypervel\Http\Exceptions\FileNotFoundException;
use Hypervel\Http\Exceptions\FormSizeFileException;
use Hypervel\Http\Exceptions\IniSizeFileException;
use Hypervel\Http\Exceptions\NoFileException;
use Hypervel\Http\Exceptions\NoTmpDirFileException;
use Hypervel\Http\Exceptions\PartialFileException;
use Hypervel\Http\Testing\FileFactory;
use Hypervel\Support\FileinfoMimeTypeGuesser;
use Hypervel\Support\MimeTypeExtensionGuesser;
use Psr\Http\Message\StreamInterface;

class UploadedFile extends HyperfUploadedFile
{
    use Macroable;

    protected static array $errors = [
        UPLOAD_ERR_INI_SIZE => 'The file "%s" exceeds your upload_max_filesize ini directive (limit is %d KiB).',
        UPLOAD_ERR_FORM_SIZE => 'The file "%s" exceeds the upload limit defined in your form.',
        UPLOAD_ERR_PARTIAL => 'The file "%s" was only partially uploaded.',
        UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
        UPLOAD_ERR_CANT_WRITE => 'The file "%s" could not be written on disk.',
        UPLOAD_ERR_NO_TMP_DIR => 'File could not be uploaded: missing temporary directory.',
        UPLOAD_ERR_EXTENSION => 'File upload was stopped by a PHP extension.',
    ];

    protected ?string $mimeType = null;

    protected ?string $hashName = null;

    protected string $originalPath;

    protected bool $isMoved = false;

    public function __construct(
        string $path,
        string $originalName,
        ?string $mimeType = null,
        ?int $error = null,
        ?int $size = null,
        protected bool $test = false,
    ) {
        $error = $error ?: UPLOAD_ERR_OK;
        $hasError = $error !== UPLOAD_ERR_OK;
        if (! $hasError && ! is_file($path)) {
            throw new FileNotFoundException($path);
        }

        $this->originalPath = strtr($originalName, '\\', '/');

        parent::__construct(
            $path,
            is_null($size) ? ($hasError ? 0 : filesize($path)) : $size,
            $error,
            $this->getName($originalName),
            $mimeType ?: 'application/octet-stream'
        );
    }

    /**
     * Begin creating a new file fake.
     */
    public static function fake(): FileFactory
    {
        return new FileFactory();
    }

    /**
     * Returns the original file name.
     *
     * It is extracted from the request from which the file has been uploaded.
     * This should not be considered as a safe value to use for a file name on your servers.
     */
    public function getClientOriginalName(): string
    {
        return $this->getClientFilename();
    }

    /**
     * Returns the original file extension.
     *
     * It is extracted from the original file name that was uploaded.
     * This should not be considered as a safe value to use for a file name on your servers.
     */
    public function getClientOriginalExtension(): string
    {
        return pathinfo($this->getClientFilename(), PATHINFO_EXTENSION);
    }

    /**
     * Returns the original file full path.
     *
     * It is extracted from the request from which the file has been uploaded.
     * This should not be considered as a safe value to use for a file name/path on your servers.
     *
     * If this file was uploaded with the "webkitdirectory" upload directive, this will contain
     * the path of the file relative to the uploaded root directory. Otherwise this will be identical
     * to getClientOriginalName().
     */
    public function getClientOriginalPath(): string
    {
        return $this->originalPath;
    }

    /**
     * Returns the file mime type.
     *
     * The client mime type is extracted from the request from which the file
     * was uploaded, so it should not be considered as a safe value.
     *
     * For a trusted mime type, use getMimeType() instead (which guesses the mime
     * type based on the file content).
     *
     * @see getMimeType()
     */
    public function getClientMimeType(): string
    {
        return $this->getClientMediaType();
    }

    /**
     * Returns the extension based on the client mime type.
     *
     * If the mime type is unknown, returns null.
     *
     * This method uses the mime type as guessed by getClientMimeType()
     * to guess the file extension. As such, the extension returned
     * by this method cannot be trusted.
     *
     * For a trusted extension, use guessExtension() instead (which guesses
     * the extension based on the guessed mime type for the file).
     *
     * @see guessExtension()
     * @see getClientMimeType()
     */
    public function guessClientExtension(): ?string
    {
        $clientExtension = $this->getClientOriginalExtension();
        $extensions = ApplicationContext::getContainer()
            ->get(MimeTypeExtensionGuesser::class)
            ->guessExtensions($this->getClientMediaType());

        return in_array($clientExtension, $extensions)
            ? $clientExtension
            : ($extensions[0] ?? null);
    }

    /**
     * Get the file's extension supplied by the client.
     */
    public function clientExtension(): string
    {
        return $this->guessClientExtension();
    }

    /**
     * Returns the extension based on the mime type.
     *
     * If the mime type is unknown, returns null.
     *
     * This method uses the mime type as guessed by getMimeType()
     * to guess the file extension.
     *
     * @see MimeTypes
     * @see getMimeType()
     */
    public function guessExtension(): ?string
    {
        return $this->guessClientExtension();
    }

    /**
     * Returns the extension based on the mime type.
     *
     * If the mime type is unknown, returns empty string (to be compatible with Hyperf).
     *
     * This method uses the mime type by getMimeType()
     * to guess the file extension.
     *
     * @see MimeTypes
     * @see getMimeType()
     */
    public function getExtension(): string
    {
        // Fix buggy getExtension method in HyperfUploadedFile.
        // A file extension should not rely on unsafe client data
        // and it's not mandatory for a file.
        $clientExtension = $this->getClientOriginalExtension();
        $extensions = ApplicationContext::getContainer()
            ->get(MimeTypeExtensionGuesser::class)
            ->guessExtensions($this->getMimeType());

        return in_array($clientExtension, $extensions)
            ? $clientExtension
            : ($extensions ? $extensions[0] : '');
    }

    /**
     * Get the file's extension.
     */
    public function extension(): string
    {
        return $this->getExtension();
    }

    /**
     * Get the fully qualified path to the file.
     */
    public function path(): string
    {
        return $this->getRealPath();
    }

    /**
     * Returns the mime type of the file.
     *
     * The mime type is guessed using a MimeTypeGuesserInterface instance,
     * which uses finfo_file() then the mime_content_type() function,
     * depending on which of those are available.
     *
     * @see MimeTypes
     */
    public function getMimeType(): string
    {
        if (is_string($this->mimeType)) {
            return $this->mimeType;
        }

        $fileInfoGuesser = ApplicationContext::getContainer()
            ->get(FileinfoMimeTypeGuesser::class);

        $mimeType = $fileInfoGuesser->isGuesserSupported()
            ? $fileInfoGuesser->guessMimeType($this->getPathname())
            : mime_content_type($this->getPathname());

        if (! $mimeType) {
            throw new FileException(sprintf('The mime type of the file "%s" could not be guessed.', $this->getPathname()));
        }

        return $this->mimeType = $mimeType;
    }

    public function getContent(): string
    {
        $content = file_get_contents($this->getPathname());

        if ($content === false) {
            throw new FileException(sprintf('Could not get the content of the file "%s".', $this->getPathname()));
        }

        return $content;
    }

    /**
     * Returns whether the file has been uploaded with HTTP and no error occurred.
     */
    public function isValid(): bool
    {
        return $this->test
            ? $this->getError() === UPLOAD_ERR_OK
            : parent::isValid();
    }

    /**
     * Get a filename for the file.
     */
    public function hashName(?string $path = null): string
    {
        if ($path) {
            $path = rtrim($path, '/') . '/';
        }

        $hash = $this->hashName ?: $this->hashName = Str::random(40);

        if ($extension = $this->getExtension()) {
            $extension = '.' . $extension;
        }

        return $path . $hash . $extension;
    }

    /**
     * Store the uploaded file on a filesystem disk.
     */
    public function store(string $path = '', array|string $options = []): false|string
    {
        return $this->storeAs($path, $this->hashName(), $this->parseOptions($options));
    }

    /**
     * Store the uploaded file on a filesystem disk.
     */
    public function storeAs(string $path, null|array|string $name = null, array|string $options = []): false|string
    {
        if (is_null($name) || is_array($name)) {
            [$path, $name, $options] = ['', $path, $name ?? []];
        }

        $options = $this->parseOptions($options);

        $disk = Arr::pull($options, 'disk');

        return ApplicationContext::getContainer()
            ->get(FilesystemManager::class)
            ->disk($disk)
            ->putFileAs($path, $this, $name, $options);
    }

    /**
     * Get the contents of the uploaded file.
     */
    public function get(): false|string
    {
        if (! $this->isValid()) {
            throw new FileNotFoundException("File does not exist at path {$this->getPathname()}.");
        }

        return file_get_contents($this->getPathname());
    }

    /**
     * Retrieve a stream representing the uploaded file.
     * This method MUST return a StreamInterface instance, representing the
     * uploaded file. The purpose of this method is to allow utilizing native PHP
     * stream functionality to manipulate the file upload, such as
     * stream_copy_to_stream() (though the result will need to be decorated in a
     * native PHP stream wrapper to work with such functions).
     * If the moveTo() method has been called previously, this method MUST raise
     * an exception.
     *
     * @return StreamInterface stream representation of the uploaded file
     * @throws FileException in cases when no stream is available or can be
     *                       created
     */
    public function getStream(): StreamInterface
    {
        if ($this->isMoved) {
            throw new FileException('Uploaded file is moved.');
        }

        return StandardStream::create(fopen($this->getPathname(), 'r+'));
    }

    /**
     * Moves the file to a new location.
     *
     * @throws FileException if, for any reason, the file could not have been moved
     */
    public function move(string $directory, ?string $name = null): UploadedFile
    {
        if ($this->isValid()) {
            $target = $this->getTargetPath($directory, $name);

            $this->isMoved = php_sapi_name() == 'cli' ? rename($this->getPathname(), $target) : move_uploaded_file($this->getPathname(), $target);
            if (! $this->isMoved) {
                throw new FileException(sprintf('Could not move the file "%s" to "%s".', $this->getPathname(), $target));
            }

            @chmod($target, 0666 & ~umask());

            return new static(
                $target,
                $this->getClientFilename(),
                $this->getClientMimeType(),
                UPLOAD_ERR_OK,
                $this->getSize()
            );
        }

        switch ($this->getError()) {
            case UPLOAD_ERR_INI_SIZE:
                throw new IniSizeFileException($this->getErrorMessage());
            case UPLOAD_ERR_FORM_SIZE:
                throw new FormSizeFileException($this->getErrorMessage());
            case UPLOAD_ERR_PARTIAL:
                throw new PartialFileException($this->getErrorMessage());
            case UPLOAD_ERR_NO_FILE:
                throw new NoFileException($this->getErrorMessage());
            case UPLOAD_ERR_CANT_WRITE:
                throw new CannotWriteFileException($this->getErrorMessage());
            case UPLOAD_ERR_NO_TMP_DIR:
                throw new NoTmpDirFileException($this->getErrorMessage());
            case UPLOAD_ERR_EXTENSION:
                throw new ExtensionFileException($this->getErrorMessage());
        }

        throw new FileException($this->getErrorMessage());
    }

    /**
     * Returns the maximum size of an uploaded file as configured in php.ini.
     *
     * @return float|int The maximum size of an uploaded file in bytes (returns float if size > PHP_INT_MAX)
     */
    public static function getMaxFilesize(): float|int
    {
        $sizePostMax = self::parseFilesize(ini_get('post_max_size'));
        $sizeUploadMax = self::parseFilesize(ini_get('upload_max_filesize'));

        return min($sizePostMax ?: PHP_INT_MAX, $sizeUploadMax ?: PHP_INT_MAX);
    }

    /**
     * Returns locale independent base name of the given path.
     */
    protected function getName(string $name): string
    {
        $originalName = str_replace('\\', '/', $name);
        $pos = strrpos($originalName, '/');

        return $pos === false ? $originalName : substr($originalName, $pos + 1);
    }

    /**
     * Create a new file instance from a base instance.
     */
    public static function createFromBase(HyperfUploadedFile $file, bool $isTest = false): static
    {
        return new static(
            $file->getPathname(),
            $file->getClientFilename(),
            $file->getClientMediaType(),
            $file->getError(),
            $file->getSize(),
            $isTest
        );
    }

    protected static function parseFilesize(string $size): float|int
    {
        if ($size === '') {
            return 0;
        }

        $size = strtolower($size);

        $max = ltrim($size, '+');
        if (str_starts_with($max, '0x')) {
            $max = intval($max, 16);
        } elseif (str_starts_with($max, '0')) {
            $max = intval($max, 8);
        } else {
            $max = (int) $max;
        }

        switch (substr($size, -1)) {
            case 't':
                $max *= 1024;
                // no break
            case 'g':
                $max *= 1024;
                // no break
            case 'm':
                $max *= 1024;
                // no break
            case 'k':
                $max *= 1024;
        }

        return $max;
    }

    /**
     * Parse and format the given options.
     */
    protected function parseOptions(array|string $options): array
    {
        if (is_string($options)) {
            $options = ['disk' => $options];
        }

        return $options;
    }

    /**
     * Returns an informative upload error message.
     */
    public function getErrorMessage(): string
    {
        $errorCode = $this->getError();
        $maxFilesize = $errorCode === UPLOAD_ERR_INI_SIZE ? self::getMaxFilesize() / 1024 : 0;
        $message = static::$errors[$errorCode] ?? 'The file "%s" was not uploaded due to an unknown error.';

        /* @phpstan-ignore-next-line */
        return sprintf($message, $this->getClientOriginalName(), $maxFilesize);
    }

    protected function getTargetPath(string $directory, ?string $name = null): string
    {
        if (! is_dir($directory)) {
            if (@mkdir($directory, 0777, true) === false && ! is_dir($directory)) {
                throw new FileException(sprintf('Unable to create the "%s" directory.', $directory));
            }
        } elseif (! is_writable($directory)) {
            throw new FileException(sprintf('Unable to write in the "%s" directory.', $directory));
        }

        return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . (is_null($name) ? $this->getBasename() : $this->getName($name));
    }
}
