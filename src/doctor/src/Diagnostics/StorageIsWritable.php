<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\ActiveDrivers;
use Hypervel\Doctor\Support\Configured;
use Hypervel\Doctor\Support\Details;
use RuntimeException;

class StorageIsWritable extends Diagnostic implements Fixable
{
    public string $name = 'Storage is writable';

    public string $group = 'storage';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'writable' => 'The application storage directories are writable.',
            'not-writable' => Message::make(
                summary: 'The application cannot write to every required storage directory.',
                remediation: 'Ensure the listed storage and bootstrap cache directories exist and are writable by the PHP process.',
                confirmation: 'Make the storage directories writable?',
            ),
            'still-not-writable' => 'Some storage directories are still not writable.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $failures = $this->failures();

        if ($failures === []) {
            return $this->pass('writable');
        }

        return $this->fail('not-writable')
            ->fixable()
            ->withDetails(Details::failures($failures));
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        $changed = [];

        foreach (array_keys($this->failures()) as $path) {
            if (! is_dir($path)) {
                if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
                    throw new RuntimeException(sprintf('Unable to create [%s].', $path));
                }

                $changed[] = sprintf('Created %s.', $path);
            }

            if (! $this->canWriteToDirectory($path) && ! @chmod($path, (fileperms($path) & 07777) | 0700)) {
                throw new RuntimeException(sprintf('Unable to change permissions for [%s].', $path));
            }
        }

        $failures = $this->failures();

        if ($failures !== []) {
            return $this->fixFailed('still-not-writable')
                ->withDetails(Details::failures($failures));
        }

        if ($changed === []) {
            return $this->fixed('writable');
        }

        return $this->fixed('writable')
            ->withDetails(implode(PHP_EOL, $changed));
    }

    /**
     * Get required storage and configured cache directories.
     *
     * @return list<string>
     */
    private function paths(): array
    {
        $application = app();
        $paths = [
            storage_path(),
            storage_path('app'),
            storage_path('framework'),
            storage_path('framework/cache'),
            storage_path('framework/sessions'),
            storage_path('framework/views'),
            storage_path('logs'),
            dirname($application->getCachedConfigPath()),
            dirname($application->getCachedRoutesPath()),
            dirname($application->getCachedEventsPath()),
            dirname($application->getCachedPackagesPath()),
        ];

        if (($path = Configured::string('view.compiled')) !== null) {
            $paths[] = $path;
        }

        if (in_array(config('session.driver'), ['file', 'native'], true)
            && ($path = Configured::string('session.files')) !== null) {
            $paths[] = $path;
        }

        $default = Configured::string('cache.default');

        foreach ($default === null ? [] : ActiveDrivers::cacheStores($default) as $store) {
            if (config("cache.stores.{$store}.driver") === 'file'
                && ($path = Configured::string("cache.stores.{$store}.path")) !== null) {
                $paths[] = $path;
                $paths[] = config("cache.stores.{$store}.lock_path") ?? $path;
            }
        }

        return array_values(array_unique($paths));
    }

    /**
     * Get directories that cannot accept a temporary write.
     *
     * @return array<string, string>
     */
    private function failures(): array
    {
        $failures = [];

        foreach ($this->paths() as $path) {
            if (! is_dir($path)) {
                $failures[$path] = 'directory does not exist';

                continue;
            }

            if (! $this->canWriteToDirectory($path)) {
                $failures[$path] = 'directory is not writable';
            }
        }

        return $failures;
    }

    /**
     * Determine whether a temporary file can be created and removed.
     */
    private function canWriteToDirectory(string $path): bool
    {
        $file = $path . DIRECTORY_SEPARATOR . '.doctor-write-test-' . str_replace('.', '', uniqid('', true));

        $handle = @fopen($file, 'x');

        if ($handle === false) {
            return false;
        }

        fclose($handle);

        return @unlink($file);
    }
}
