<?php

declare(strict_types=1);

namespace Hypervel\Http\Concerns;

use Hypervel\Http\UploadedFile;
use Hypervel\Support\Arr;
use Hypervel\Support\Fluent;
use Hypervel\Support\Traits\Dumpable;
use Hypervel\Support\Traits\InteractsWithData;
use SplFileInfo;
use Symfony\Component\HttpFoundation\InputBag;

trait InteractsWithInput
{
    use Dumpable;
    use InteractsWithData;

    /**
     * Retrieve a server variable from the request.
     */
    public function server(?string $key = null, string|array|int|float|null $default = null): string|array|int|float|null
    {
        return $this->retrieveItem('server', $key, $default);
    }

    /**
     * Determine if a header is set on the request.
     */
    public function hasHeader(string $key): bool
    {
        return ! is_null($this->header($key));
    }

    /**
     * Retrieve a header from the request.
     */
    public function header(?string $key = null, string|array|null $default = null): string|array|null
    {
        return $this->retrieveItem('headers', $key, $default);
    }

    /**
     * Get the bearer token from the request headers.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization', '');

        $position = strripos($header, 'Bearer ');

        if ($position !== false) {
            $header = substr($header, $position + 7);

            return str_contains($header, ',') ? strstr($header, ',', true) : $header;
        }

        return null;
    }

    /**
     * Get the keys for all of the input and files.
     */
    public function keys(): array
    {
        return array_merge(array_keys($this->input()), $this->files->keys());
    }

    /**
     * Get all of the input and files for the request.
     */
    public function all(mixed $keys = null): array
    {
        $input = array_replace_recursive($this->input(), $this->allFiles());

        if (! $keys) {
            return $input;
        }

        $results = [];

        foreach (is_array($keys) ? $keys : func_get_args() as $key) {
            Arr::set($results, $key, Arr::get($input, $key));
        }

        return $results;
    }

    /**
     * Retrieve an input item from the request.
     */
    public function input(?string $key = null, mixed $default = null): mixed
    {
        return data_get(
            $this->getInputSource()->all() + $this->query->all(),
            $key,
            $default
        );
    }

    /**
     * Retrieve input from the request as a Fluent object instance.
     */
    public function fluent(array|string|null $key = null, array $default = []): Fluent
    {
        $value = is_array($key) ? $this->only($key) : $this->input($key);

        return new Fluent($value ?? $default);
    }

    /**
     * Retrieve a query string item from the request.
     */
    public function query(?string $key = null, string|array|null $default = null): string|array|null
    {
        return $this->retrieveItem('query', $key, $default);
    }

    /**
     * Retrieve a request payload item from the request.
     */
    public function post(?string $key = null, string|array|null $default = null): string|array|null
    {
        return $this->retrieveItem('request', $key, $default);
    }

    /**
     * Determine if a cookie is set on the request.
     */
    public function hasCookie(string $key): bool
    {
        return ! is_null($this->cookie($key));
    }

    /**
     * Retrieve a cookie from the request.
     */
    public function cookie(?string $key = null, string|array|null $default = null): string|array|null
    {
        return $this->retrieveItem('cookies', $key, $default);
    }

    /**
     * Get an array of all of the files on the request.
     *
     * @return array<string, UploadedFile|UploadedFile[]>
     */
    public function allFiles(): array
    {
        $files = $this->files->all();

        return $this->convertedFiles = $this->convertedFiles ?? $this->convertUploadedFiles($files);
    }

    /**
     * Convert the given array of Symfony UploadedFiles to custom Hypervel UploadedFiles.
     *
     * @param array<string, \Symfony\Component\HttpFoundation\File\UploadedFile|\Symfony\Component\HttpFoundation\File\UploadedFile[]> $files
     * @return array<string, UploadedFile|UploadedFile[]>
     */
    protected function convertUploadedFiles(array $files): array
    {
        return array_map(function ($file) {
            if (is_null($file) || (is_array($file) && empty(array_filter($file)))) { /* @phpstan-ignore function.impossibleType, arrayFilter.same (PHP's $_FILES can produce null entries despite the docblock type) */
                return $file;
            }

            return is_array($file)
                ? $this->convertUploadedFiles($file)
                : UploadedFile::createFromBase($file);
        }, $files);
    }

    /**
     * Determine if the uploaded data contains a file.
     */
    public function hasFile(string $key): bool
    {
        if (! is_array($files = $this->file($key))) {
            $files = [$files];
        }

        foreach ($files as $file) {
            if ($this->isValidFile($file)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check that the given file is a valid file instance.
     */
    protected function isValidFile(mixed $file): bool
    {
        return $file instanceof SplFileInfo && $file->getPath() !== '';
    }

    /**
     * Retrieve a file from the request.
     *
     * @return ($key is null ? array<string, UploadedFile|UploadedFile[]> : null|UploadedFile|UploadedFile[])
     */
    public function file(?string $key = null, mixed $default = null): UploadedFile|array|null
    {
        return data_get($this->allFiles(), $key, $default);
    }

    /**
     * Retrieve data from the instance.
     */
    protected function data(?string $key = null, mixed $default = null): mixed
    {
        return $this->input($key, $default);
    }

    /**
     * Retrieve a parameter item from a given source.
     */
    protected function retrieveItem(string $source, ?string $key, string|array|int|float|null $default): string|array|int|float|null
    {
        if (is_null($key)) {
            return $this->{$source}->all();
        }

        if ($this->{$source} instanceof InputBag) {
            return $this->{$source}->all()[$key] ?? $default;
        }

        return $this->{$source}->get($key, $default);
    }

    /**
     * Dump the items.
     */
    public function dump(mixed $keys = []): static
    {
        $keys = is_array($keys) ? $keys : func_get_args();

        dump(count($keys) > 0 ? $this->only($keys) : $this->all());

        return $this;
    }
}
