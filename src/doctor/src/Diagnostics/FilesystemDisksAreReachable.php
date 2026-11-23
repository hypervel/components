<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Coroutine\Coroutine;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Configured;
use Hypervel\Filesystem\LocalFilesystemAdapter;
use Hypervel\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class FilesystemDisksAreReachable extends Diagnostic
{
    public string $name = 'Filesystem disks connect';

    public string $group = 'storage';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'not-configured' => 'The application does not have a default filesystem disk configured.',
            'missing-driver' => Message::make(
                summary: 'The default filesystem disk [{disk}] does not have a configured driver.',
                remediation: 'Set FILESYSTEM_DISK to a disk with a driver in config/filesystems.php.',
            )->link(Link::docs('filesystem', 'configuration')),
            'unreachable' => Message::make(
                summary: 'The application cannot reach the default filesystem disk [{disk}].',
                remediation: 'Check filesystem disk roots, credentials, and network access.',
            )->link(Link::docs('filesystem', 'configuration')),
            'reachable' => 'The application can reach the default filesystem disk.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $disk = Configured::string('filesystems.default');

        if ($disk === null) {
            return $this->skip('not-configured');
        }

        try {
            $resolved = Storage::getDisks()[$disk] ?? null;

            if ($resolved instanceof LocalFilesystemAdapter) {
                $this->probeLocalDisk($resolved->getConfig(), $resolved);
            } elseif ($resolved !== null) {
                $resolved->exists('.hypervel-doctor');
            } else {
                $configuration = Configured::array("filesystems.disks.{$disk}");
                $driver = $configuration['driver'] ?? null;

                if (! is_string($driver) || trim($driver) === '') {
                    return $this->fail('missing-driver', ['disk' => $disk]);
                }

                // Resolving a local disk would create the missing root that Doctor is checking.
                if ($driver === 'local') {
                    $this->probeLocalDisk($configuration);
                } else {
                    Storage::disk($disk)->exists('.hypervel-doctor');
                }
            }
        } catch (Throwable $e) {
            if (Coroutine::causedByCancellation($e)) {
                throw $e;
            }

            return $this->fail('unreachable', ['disk' => $disk])
                ->withDetails($e->getMessage());
        }

        return $this->pass('reachable');
    }

    /**
     * Probe a local filesystem disk.
     *
     * @param array<string, mixed> $configuration
     */
    private function probeLocalDisk(array $configuration, ?LocalFilesystemAdapter $disk = null): void
    {
        $root = $configuration['root'] ?? null;

        if (! is_string($root) || $root === '') {
            if ($disk === null) {
                throw new RuntimeException('The local disk root is not configured.');
            }

            if (! $disk->directoryExists('')) {
                throw new RuntimeException('The registered local disk root does not exist.');
            }

            return;
        }

        if (! is_dir($root) || ! is_readable($root)) {
            throw new RuntimeException(sprintf('The local disk root [%s] is not readable.', $root));
        }
    }
}
