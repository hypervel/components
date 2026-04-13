<?php

declare(strict_types=1);

namespace Hypervel\Http;

use Hypervel\Container\Container;
use Hypervel\Contracts\Filesystem\Factory as FilesystemFactory;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Http\Testing\FileFactory;
use Hypervel\Support\Arr;
use Hypervel\Support\Traits\Macroable;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;

class UploadedFile extends SymfonyUploadedFile
{
    use FileHelpers;
    use Macroable;

    /**
     * Begin creating a new file fake.
     */
    public static function fake(): FileFactory
    {
        return new FileFactory;
    }

    /**
     * Store the uploaded file on a filesystem disk.
     */
    public function store(string $path = '', array|string $options = []): string|false
    {
        return $this->storeAs($path, $this->hashName(), $this->parseOptions($options));
    }

    /**
     * Store the uploaded file on a filesystem disk with public visibility.
     */
    public function storePublicly(string $path = '', array|string $options = []): string|false
    {
        $options = $this->parseOptions($options);

        $options['visibility'] = 'public';

        return $this->storeAs($path, $this->hashName(), $options);
    }

    /**
     * Store the uploaded file on a filesystem disk with public visibility.
     */
    public function storePubliclyAs(string $path, array|string|null $name = null, array|string $options = []): string|false
    {
        if (is_null($name) || is_array($name)) {
            [$path, $name, $options] = ['', $path, $name ?? []];
        }

        $options = $this->parseOptions($options);

        $options['visibility'] = 'public';

        return $this->storeAs($path, $name, $options);
    }

    /**
     * Store the uploaded file on a filesystem disk.
     */
    public function storeAs(string $path, array|string|null $name = null, array|string $options = []): string|false
    {
        if (is_null($name) || is_array($name)) {
            [$path, $name, $options] = ['', $path, $name ?? []];
        }

        $options = $this->parseOptions($options);

        $disk = Arr::pull($options, 'disk');

        return Container::getInstance()->make(FilesystemFactory::class)->disk($disk)->putFileAs(
            $path,
            $this,
            $name,
            $options
        );
    }

    /**
     * Get the contents of the uploaded file.
     *
     * @throws FileNotFoundException
     */
    public function get(): string|false
    {
        if (! $this->isValid()) {
            throw new FileNotFoundException("File does not exist at path {$this->getPathname()}.");
        }

        return file_get_contents($this->getPathname());
    }

    /**
     * Get the file's extension supplied by the client.
     */
    public function clientExtension(): string
    {
        return $this->guessClientExtension();
    }

    /**
     * Create a new file instance from a base instance.
     */
    public static function createFromBase(SymfonyUploadedFile $file, bool $test = false): static
    {
        return $file instanceof static ? $file : new static(
            $file->getPathname(),
            $file->getClientOriginalPath(),
            $file->getClientMimeType(),
            $file->getError(),
            $test
        );
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
}
