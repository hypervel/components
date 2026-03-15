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
use Throwable;

class GoogleCloudStorageAdapter extends FilesystemAdapter
{
    public const DEFAULT_API_ENDPOINT = 'https://storage.googleapis.com';

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

        if (Arr::get($this->config, 'storageApiUri')) {
            $storageApiUri = Arr::get($this->config, 'storageApiUri');
        }

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
        return $this->readStreamWithOptions(
            $path,
            ($this->config['stream_reads'] ?? false) ? ['restOptions' => ['stream' => true]] : []
        );
    }

    /**
     * Get a resource to read the partial file.
     *
     * @return null|resource the path resource or null on failure
     */
    public function readStreamRange(string $path, ?int $start, ?int $end): mixed
    {
        return $this->readStreamWithOptions(
            $path,
            [
                'restOptions' => [
                    'headers' => [
                        'Range' => "bytes={$start}-{$end}",
                    ],
                    ...(($this->config['stream_reads'] ?? false) ? ['stream' => true] : []),
                ],
            ]
        );
    }

    /**
     * Get the underlying GCS client.
     */
    public function getClient(): StorageClient
    {
        return $this->client;
    }

    private function readStreamWithOptions(string $path, array $options): mixed
    {
        $prefixedPath = $this->prefixer->prefixPath($path);

        try {
            $stream = $this->getBucket()->object($prefixedPath)->downloadAsStream($options)->detach();
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, $exception->getMessage(), $exception);
        }

        if (! is_resource($stream)) {
            throw UnableToReadFile::fromLocation($path, 'Downloaded object does not contain a file resource.');
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
