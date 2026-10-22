<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Configured;
use Hypervel\Support\Facades\Storage;
use RuntimeException;
use Swoole\Coroutine\CanceledException;
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
            'disk-missing' => 'The default filesystem disk [{disk}] is not configured.',
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

        $configuration = $this->configuration($disk);

        if ($configuration === null) {
            return $this->skip('disk-missing', ['disk' => $disk]);
        }

        try {
            $this->probe($disk, $configuration);
        } catch (CanceledException $exception) {
            throw $exception;
        } catch (Throwable $e) {
            return $this->fail('unreachable', ['disk' => $disk])
                ->withDetails($e->getMessage());
        }

        return $this->pass('reachable');
    }

    /**
     * Probe a filesystem disk.
     *
     * @param array<string, mixed> $configuration
     */
    private function probe(string $disk, array $configuration): void
    {
        if (($configuration['driver'] ?? null) === 'local') {
            $this->probeLocalDisk($configuration);

            return;
        }

        Storage::disk($disk)->exists('.hypervel-doctor');
    }

    /**
     * Probe a local filesystem disk.
     *
     * @param array<string, mixed> $configuration
     */
    private function probeLocalDisk(array $configuration): void
    {
        $root = $configuration['root'] ?? null;

        if (! is_string($root) || $root === '') {
            throw new RuntimeException('The local disk root is not configured.');
        }

        if (! is_dir($root) || ! is_readable($root)) {
            throw new RuntimeException(sprintf('The local disk root [%s] is not readable.', $root));
        }
    }

    /**
     * Get the default filesystem disk configuration.
     *
     * @return null|array<string, mixed>
     */
    private function configuration(string $disk): ?array
    {
        $configuration = config("filesystems.disks.{$disk}");

        return is_array($configuration) ? $configuration : null;
    }
}
