<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use DateTimeInterface;
use Google\Cloud\Storage\Bucket;
use Google\Cloud\Storage\StorageClient;
use Hypervel\Support\Arr;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\GoogleCloudStorage\GoogleCloudStorageAdapter as FlysystemGoogleCloudAdapter;
use League\Flysystem\UnableToReadFile;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
use Throwable;

class GoogleCloudStorageAdapter extends FilesystemAdapter
{
    public const string DEFAULT_API_ENDPOINT = 'https://storage.googleapis.com';

    public function __construct(
        FilesystemOperator $driver,
        FlysystemGoogleCloudAdapter $adapter,
        protected array $config,
        protected StorageClient $client
    ) {
        parent::__construct($driver, $adapter, $config);
    }

    /**
     * Get the URL for the file at the given path.
     *
     * @throws RuntimeException
     */
    public function url(string $path): string
    {
        $storageApiUri = Arr::get($this->config, 'storageApiUri')
            ?: static::DEFAULT_API_ENDPOINT . '/' . ltrim(Arr::get($this->config, 'bucket'), '/');

        return $this->concatPathToUrl($storageApiUri, $this->prefixer->prefixPath($path));
    }

    /**
     * Get a temporary URL for the file at the given path.
     */
    public function temporaryUrl(string $path, DateTimeInterface $expiration, array $options = []): string
    {
        if (Arr::get($this->config, 'storageApiUri')) {
            $options['bucketBoundHostname'] = Arr::get($this->config, 'storageApiUri');
        }

        return $this->getBucket()->object($this->prefixer->prefixPath($path))->signedUrl($expiration, $options);
    }

    /**
     * Get a temporary upload URL for the file at the given path.
     */
    public function temporaryUploadUrl(string $path, DateTimeInterface $expiration, array $options = []): array|string
    {
        if (Arr::get($this->config, 'storageApiUri')) {
            $options['bucketBoundHostname'] = Arr::get($this->config, 'storageApiUri');
        }

        return $this->getBucket()->object($this->prefixer->prefixPath($path))->beginSignedUploadSession($options);
    }

    /**
     * Get a resource to read the file.
     *
     * @return null|resource the path resource or null on failure
     */
    public function readStream(string $path): mixed
    {
        try {
            return $this->readStreamRangeOrFail($path);
        } catch (UnableToReadFile $exception) {
            throw_if($this->throwsExceptions(), $exception);
            $this->report($exception);

            return null;
        }
    }

    /**
     * Get a resource to read the partial file.
     *
     * @return null|resource the path resource or null on failure
     */
    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        [$start, $end] = $this->normalizeStreamRange($start, $end);

        if ($start === null && $end === null) {
            return $this->readStream($path);
        }

        try {
            return $this->readStreamRangeOrFail($path, $start, $end);
        } catch (UnableToReadFile $exception) {
            throw_if($this->throwsExceptions(), $exception);
            $this->report($exception);

            return null;
        }
    }

    /**
     * Open a whole-object or ranged stream without applying the disk's failure policy.
     *
     * @return resource
     */
    public function readStreamRangeOrFail(string $path, ?int $start = null, ?int $end = null): mixed
    {
        [$start, $end] = $this->normalizeStreamRange($start, $end);
        $options = ($this->config['stream_reads'] ?? true) ? ['restOptions' => ['stream' => true]] : [];

        if ($start !== null || $end !== null) {
            $options['restOptions']['headers']['Range'] = "bytes={$start}-{$end}";
        }

        return $this->readStreamWithOptions($path, $options);
    }

    /**
     * Get the underlying GCS client.
     */
    public function getClient(): StorageClient
    {
        return $this->client;
    }

    /**
     * Read an object without applying the disk's failure policy.
     *
     * @return resource
     */
    private function readStreamWithOptions(string $path, array $options): mixed
    {
        $prefixedPath = $this->prefixer->prefixPath($path);

        try {
            $stream = $this->getBucket()->object($prefixedPath)->downloadAsStream($options)->detach();
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }

        if (! is_resource($stream)) {
            throw UnableToReadFile::fromLocation(
                $path,
                'Downloaded object does not contain a file resource.',
            );
        }

        return $stream;
    }

    private function getBucket(): Bucket
    {
        return $this->client->bucket(Arr::get($this->config, 'bucket'));
    }

    /**
     * Determine if temporary URLs can be generated.
     */
    public function providesTemporaryUrls(): bool
    {
        return true;
    }
}
