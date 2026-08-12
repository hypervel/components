<?php

declare(strict_types=1);

namespace Hypervel\Testbench\Workbench\Actions;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Support\Collection;
use Hypervel\Testbench\Contracts\Config as ConfigContract;
use RuntimeException;
use Throwable;

use function Hypervel\Testbench\is_symlink;
use function Hypervel\Testbench\join_paths;
use function Hypervel\Testbench\package_path;

/**
 * @internal
 *
 * @codeCoverageIgnore
 */
final class AddAssetSymlinkFolders
{
    /**
     * Construct a new action.
     */
    public function __construct(
        private readonly Filesystem $files,
        private readonly ConfigContract $config,
    ) {
    }

    /**
     * Execute the action.
     */
    public function handle(): void
    {
        /** @var array<int, array{from: string, to: string, reverse?: bool}> $sync */
        $sync = $this->config->getWorkbenchAttributes()['sync'];

        (new Collection($sync))
            ->map(function ($pair) {
                /** @var bool $reverse */
                $reverse = $pair['reverse'] ?? false;

                /** @var string $from */
                $from = $reverse === false ? package_path($pair['from']) : base_path($pair['from']);

                /** @var string $to */
                $to = $reverse === false ? base_path($pair['to']) : package_path($pair['to']);

                return $this->files->isDirectory($from)
                    ? ['from' => $from, 'to' => $to]
                    : null;
            })->filter()
            ->each(function ($pair) {
                /** @var array{from: string, to: string} $pair */

                /** @var string $from */
                $from = $pair['from'];

                /** @var string $to */
                $to = $pair['to'];

                $this->publish($from, $to);
            });
    }

    /**
     * Publish a verified asset symlink through staged replacement and backup.
     */
    private function publish(string $from, string $to): void
    {
        $directory = dirname($to);

        $this->files->ensureDirectoryExists($directory);

        $staged = join_paths($directory, '.' . basename($to) . '.staged');
        $backup = join_paths($directory, '.' . basename($to) . '.backup');
        $this->clearStagedLink($staged);

        try {
            $this->files->link($from, $staged);

            if (! $this->linkPointsTo($staged, $from)) {
                throw new RuntimeException("Unable to stage symlink [{$staged}].");
            }
        } catch (Throwable $throwable) {
            $this->discardLinkAfterFailure($staged, $from);

            throw $throwable;
        }

        $hasOriginal = is_symlink($to) || $this->files->exists($to);

        if ($hasOriginal && (is_symlink($backup) || $this->files->exists($backup))) {
            $this->discardLinkAfterFailure($staged, $from);

            throw new RuntimeException("Unable to back up [{$to}] because [{$backup}] already exists.");
        }

        if ($hasOriginal) {
            try {
                if (! $this->files->move($to, $backup)) {
                    throw new RuntimeException("Unable to back up [{$to}].");
                }
            } catch (Throwable $throwable) {
                $this->discardLinkAfterFailure($staged, $from);

                throw $throwable;
            }
        }

        try {
            if (! $this->files->move($staged, $to) || ! $this->linkPointsTo($to, $from)) {
                throw new RuntimeException("Unable to publish symlink [{$to}].");
            }
        } catch (Throwable $throwable) {
            $this->discardLinkAfterFailure($staged, $from);
            $this->discardLinkAfterFailure($to, $from);

            if ($hasOriginal) {
                try {
                    if (! $this->files->move($backup, $to)) {
                        throw new RuntimeException("Unable to restore [{$to}].");
                    }
                } catch (Throwable) {
                    // Preserve the publication failure when restoration also fails.
                }
            }

            throw $throwable;
        }

        if ($hasOriginal) {
            $this->deletePath($backup);
        }
    }

    /**
     * Remove a stale staged link without deleting an unowned real path.
     */
    private function clearStagedLink(string $staged): void
    {
        if (is_symlink($staged)) {
            $this->removeSymlink($staged);
        }

        clearstatcache(false, $staged);

        if (is_symlink($staged) || $this->files->exists($staged)) {
            throw new RuntimeException("Unable to clear staged symlink [{$staged}].");
        }
    }

    /**
     * Determine whether a symlink resolves to the expected target.
     */
    private function linkPointsTo(string $link, string $target): bool
    {
        if (! is_symlink($link)) {
            return false;
        }

        $resolvedLink = realpath($link);

        return $resolvedLink !== false && $resolvedLink === realpath($target);
    }

    /**
     * Discard an owned link while preserving the primary operation failure.
     */
    private function discardLinkAfterFailure(string $link, string $target): void
    {
        if (! $this->linkPointsTo($link, $target)) {
            return;
        }

        try {
            $this->removeSymlink($link);
        } catch (Throwable) {
            // The operation failure remains primary when owned-link cleanup also fails.
        }
    }

    /**
     * Remove a symlink and verify its absence.
     */
    private function removeSymlink(string $link): void
    {
        $deleted = windows_os() ? @rmdir($link) : $this->files->delete($link);
        clearstatcache(false, $link);

        if (! $deleted || is_symlink($link) || $this->files->exists($link)) {
            throw new RuntimeException("Unable to remove symlink [{$link}].");
        }
    }

    /**
     * Delete a replaced path and verify its absence.
     */
    private function deletePath(string $path): void
    {
        $deleted = match (true) {
            is_symlink($path) => windows_os() ? @rmdir($path) : $this->files->delete($path),
            $this->files->isDirectory($path) => $this->files->deleteDirectory($path),
            default => $this->files->delete($path),
        };

        clearstatcache(false, $path);

        if (! $deleted || is_symlink($path) || $this->files->exists($path)) {
            throw new RuntimeException("Unable to remove backup [{$path}].");
        }
    }
}
