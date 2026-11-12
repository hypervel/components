<?php

declare(strict_types=1);

namespace StorageDisks;

use Hypervel\Filesystem\FilesystemManager;
use Hypervel\Support\Facades\Storage;

use function PHPStan\Testing\assertType;

enum Disk: string
{
    case Cloud = 's3';
}

class TenantFilesystemManager extends FilesystemManager
{
}

function test(FilesystemManager $manager, TenantFilesystemManager $tenantManager, bool $condition, string $name): void
{
    assertType('Hypervel\Filesystem\LocalFilesystemAdapter', Storage::disk('local'));
    assertType('Hypervel\Filesystem\LocalFilesystemAdapter', Storage::disk(''));
    assertType('Hypervel\Filesystem\ClientPooledFilesystem', Storage::disk('s3'));
    assertType('Hypervel\Filesystem\ClientPooledFilesystem', Storage::disk(Disk::Cloud));
    assertType('Hypervel\Filesystem\FilesystemPoolProxy', Storage::disk('ftp'));
    assertType('Hypervel\Filesystem\FilesystemAdapter', Storage::disk('custom'));
    assertType('Hypervel\Filesystem\FilesystemPoolProxy', Storage::disk('pooled-custom'));
    // A scoped disk is built as the disk it scopes.
    assertType('Hypervel\Filesystem\ClientPooledFilesystem', Storage::disk('scoped'));
    assertType('Hypervel\Filesystem\ClientPooledFilesystem|Hypervel\Filesystem\LocalFilesystemAdapter', Storage::disk($condition ? 'local' : 's3'));

    // Disks the analysis cannot type keep the declaration.
    assertType('Hypervel\Contracts\Filesystem\Filesystem', Storage::disk($name));
    assertType('Hypervel\Contracts\Filesystem\Filesystem', Storage::disk('missing'));
    assertType('Hypervel\Contracts\Filesystem\Filesystem', Storage::disk('unsupported'));
    assertType('Hypervel\Contracts\Filesystem\Filesystem', Storage::disk('replaced'));

    // Facade calls the manager does not declare run on the default local disk: assertExists() returns
    // that disk, and it has no client, although the manager's pooled-disk mixin declares one.
    assertType('Hypervel\Filesystem\LocalFilesystemAdapter', Storage::assertExists('file.txt'));
    assertType('*ERROR*', Storage::getClient());

    assertType('Hypervel\Filesystem\FilesystemPoolProxy', $manager->drive('ftp'));
    // A manager other than the bound one can hold other configuration.
    assertType('Hypervel\Contracts\Filesystem\Filesystem', $tenantManager->disk('s3'));
}
