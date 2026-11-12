<?php

declare(strict_types=1);

namespace Hypervel\Types\Filesystem;

use Aws\S3\S3Client;
use Hypervel\Filesystem\ClientPooledFilesystem;
use Hypervel\Filesystem\FilesystemAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;

use function PHPStan\Testing\assertType;

/**
 * Verify borrow-scoped callbacks accept the disk's specific client, adapter and driver types.
 */
function borrowedAccess(ClientPooledFilesystem $pooled, FilesystemAdapter $disk): void
{
    assertType('Aws\Result', $pooled->withClient(fn (S3Client $client) => $client->listBuckets()));
    assertType('mixed', $pooled->withClient(fn ($client) => $client));

    foreach ([$pooled, $disk] as $filesystem) {
        assertType('bool', $filesystem->withAdapter(fn ($adapter) => $adapter->fileExists('file.txt')));
        assertType(AwsS3V3Adapter::class, $filesystem->withAdapter(fn (AwsS3V3Adapter $adapter) => $adapter));
        assertType('bool', $filesystem->withDriver(fn ($driver) => $driver->fileExists('file.txt')));
        assertType('string', $filesystem->withDriver(fn (Filesystem $driver) => $driver->publicUrl('file.txt')));
    }
}
