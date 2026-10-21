<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use FilesystemIterator;
use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Details;
use Hypervel\Support\Facades\Process;

class PublicStorageLinkExists extends Diagnostic implements Fixable
{
    public string $name = 'Public storage is linked';

    public string $group = 'storage';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'not-default-local-path' => 'The public disk does not use a local filesystem.',
            'not-configured' => 'No filesystem links are configured for the public disk root.',
            'unused' => 'The public storage directory does not contain public files.',
            'linked' => 'The public storage link exists.',
            'missing' => Message::make(
                summary: 'The public storage link does not exist.',
                remediation: 'Create the public storage link with `php artisan storage:link`.',
                confirmation: 'Create the public storage link using `php artisan storage:link`?',
            )->link(Link::docs('filesystem', 'the-public-disk')),
            'wrong-link' => Message::make(
                summary: 'A public storage symlink is dangling or points to the wrong target.',
                remediation: 'Review the configured links, then explicitly run `php artisan storage:link --force` to replace incorrect symlinks.',
            )->link(Link::docs('filesystem', 'the-public-disk')),
            'conflicting-path' => Message::make(
                summary: 'A file or directory occupies a configured public storage link path.',
                remediation: 'Move the conflicting path aside, then run `php artisan storage:link`.',
            )->link(Link::docs('filesystem', 'the-public-disk')),
            'creation-failed' => 'The public storage link could not be created.',
            'created' => 'The public storage link was created.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (! $this->expectsPublicStorageLink()) {
            return $this->skip('not-default-local-path');
        }

        $links = $this->expectedLinks();

        if ($links === []) {
            return $this->skip('not-configured');
        }

        if ($this->publicDiskIsUnused()) {
            return $this->skip('unused');
        }

        $failures = $this->inspectLinks($links);

        if ($failures === []) {
            return $this->pass('linked');
        }

        $code = in_array('conflicting-path', $failures, true) ? 'conflicting-path'
            : (in_array('wrong-link', $failures, true) ? 'wrong-link' : 'missing');
        $result = $this->fail($code)->withDetails($this->linkDetails($links, $failures));

        return $code === 'missing' ? $result->fixable() : $result;
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        $links = $this->expectedLinks();
        $failures = $this->inspectLinks($links);

        if ($links === [] || array_diff($failures, ['missing']) !== []) {
            return $this->fixFailed('creation-failed')->withDetails(
                $links === [] ? 'No links target the public disk root.' : $this->linkDetails($links, $failures),
            );
        }

        $process = Process::path(base_path())->run([PHP_BINARY, 'artisan', 'storage:link']);
        $failures = $this->inspectLinks($links);

        if (! $process->successful() || $failures !== []) {
            return $this->fixFailed('creation-failed')
                ->withDetails(trim($this->linkDetails($links, $failures) . PHP_EOL . Details::processOutput(
                    $process->output(),
                    $process->errorOutput(),
                    'The storage:link command exited without output.',
                )));
        }

        return $this->fixed('created');
    }

    /**
     * Determine whether the public disk can be exposed through local links.
     */
    private function expectsPublicStorageLink(): bool
    {
        $disk = config('filesystems.disks.public');

        return is_array($disk) && ($disk['driver'] ?? null) === 'local';
    }

    /**
     * Get configured links targeting the public disk root.
     *
     * @return array<string, string>
     */
    private function expectedLinks(): array
    {
        $root = $this->normalize(config()->string('filesystems.disks.public.root'));

        return array_filter(
            config()->array('filesystems.links'),
            fn (string $target): bool => $this->normalize($target) === $root,
        );
    }

    /**
     * Inspect each configured path without changing existing files or links.
     *
     * @param array<string, string> $links
     * @return array<string, 'conflicting-path'|'missing'|'wrong-link'>
     */
    private function inspectLinks(array $links): array
    {
        $failures = [];

        foreach ($links as $link => $target) {
            clearstatcache(true, $link);

            if (realpath($link) === $this->normalize($target)) {
                continue;
            }

            $failures[$link] = ! file_exists($link) && ! is_link($link) ? 'missing'
                : (is_link($link) ? 'wrong-link' : 'conflicting-path');
        }

        return $failures;
    }

    /**
     * Describe the failed paths and their expected targets.
     *
     * @param array<string, string> $links
     * @param array<string, string> $failures
     */
    private function linkDetails(array $links, array $failures): string
    {
        $details = [];

        foreach ($failures as $link => $failure) {
            $details[] = sprintf('[%s] → [%s]: %s.', $link, $links[$link], match ($failure) {
                'missing' => 'missing link',
                'wrong-link' => 'dangling or incorrect symlink; review storage:link --force',
                default => 'conflicting file or directory; move it aside before storage:link',
            });
        }

        return implode(PHP_EOL, $details);
    }

    /**
     * Determine whether the public disk contains only placeholder files.
     */
    private function publicDiskIsUnused(): bool
    {
        $path = config()->string('filesystems.disks.public.root');

        if (! is_dir($path)) {
            return true;
        }

        foreach (new FilesystemIterator($path) as $file) {
            if (! in_array($file->getFilename(), ['.gitignore', '.gitkeep'], true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Normalize a filesystem path for target comparison.
     */
    private function normalize(string $path): string
    {
        $realPath = realpath($path);

        return $realPath === false ? rtrim($path, DIRECTORY_SEPARATOR) : $realPath;
    }
}
