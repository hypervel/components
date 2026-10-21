<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Composer;
use Hypervel\Doctor\Support\Details;
use Hypervel\Support\Facades\Process;
use LogicException;

class ComposerLockIsFresh extends Diagnostic implements Fixable
{
    public string $name = 'composer.lock is fresh';

    public string $group = 'composer';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'composer-missing' => 'The application does not have a composer.json file.',
            'lock-missing' => Message::make(
                summary: 'The application does not have a composer.lock file.',
                remediation: 'Run `composer install` to generate a composer.lock file.',
                confirmation: 'Generate composer.lock and install dependencies using `composer install`?',
            ),
            'fresh' => 'composer.lock is present and fresh.',
            'stale' => Message::make(
                summary: 'composer.lock is out of date with composer.json.',
                remediation: 'Run `composer update --lock` to refresh the lock file.',
                confirmation: 'Refresh composer.lock using `composer update --lock`?',
            ),
            'constraint-mismatch' => Message::make(
                summary: 'composer.lock does not satisfy the package constraints in composer.json.',
                remediation: 'Run `composer update {packages}` to lock versions that satisfy composer.json.',
                confirmation: 'Update {packages} using `composer update {packages}`?',
            ),
            'inspection-failed' => Message::make(
                summary: 'Composer could not verify composer.lock freshness.',
                remediation: 'Run `composer validate --check-lock` and resolve the reported Composer errors.',
            ),
            'fix-failed' => 'composer.lock could not be repaired.',
            'fixed' => 'composer.lock was repaired.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (! is_file(base_path('composer.json'))) {
            return $this->skip('composer-missing');
        }

        if (! is_file(base_path('composer.lock'))) {
            return $this->fail('lock-missing')->fixable(EnvironmentMode::Local);
        }

        $process = Process::path(base_path())->run([
            ...Composer::command(),
            'validate',
            '--check-lock',
            '--no-check-publish',
            '--no-check-all',
            '--no-interaction',
            '--no-ansi',
        ]);

        if ($process->successful()) {
            return $this->pass('fresh');
        }

        $details = Details::processOutput(
            $process->output(),
            $process->errorOutput(),
            'Composer exited without lock file details.',
        );

        if (($packages = $this->mismatchedPackages($details)) !== []) {
            return $this->fail('constraint-mismatch', ['packages' => implode(' ', $packages)])
                ->fixable(EnvironmentMode::Local)
                ->withContext('packages', $packages)
                ->withDetails($this->lockFileErrors($details) ?? $details);
        }

        if ($this->reportsStaleLock($details)) {
            return $this->fail('stale')
                ->fixable(EnvironmentMode::Local)
                ->withDetails($this->lockFileErrors($details) ?? $details);
        }

        return $this->fail('inspection-failed')
            ->withDetails($details);
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        $arguments = match ($result->code) {
            'composer-lock-is-fresh.lock-missing' => ['install'],
            'composer-lock-is-fresh.stale' => ['update', '--lock'],
            'composer-lock-is-fresh.constraint-mismatch' => $this->constraintUpdateArguments($result),
            default => throw new LogicException("Unexpected composer.lock result [{$result->code}]."),
        };

        $process = Process::path(base_path())->run([
            ...Composer::command(),
            ...$arguments,
            '--no-interaction',
        ]);

        if (! $process->successful()) {
            return $this->fixFailed('fix-failed')
                ->withDetails(Details::processOutput(
                    $process->output(),
                    $process->errorOutput(),
                    'Composer exited without lock file repair details.',
                ));
        }

        return $this->fixed('fixed');
    }

    /**
     * Get the targeted Composer update arguments for constraint mismatches.
     *
     * @return list<string>
     */
    private function constraintUpdateArguments(DiagnosticResult $result): array
    {
        /** @var list<string> $packages */
        $packages = $result->context['packages'];

        return ['update', ...$packages];
    }

    /**
     * Determine whether Composer reported a lock file freshness problem.
     */
    private function reportsStaleLock(string $details): bool
    {
        return str_contains($details, '# Lock file errors')
            || str_contains($details, 'lock file is not up to date');
    }

    /**
     * Extract the factual lock file error lines from Composer's validation output.
     *
     * Composer intersperses its own remediation advice and documentation links
     * with the errors; only the "- " bulleted error lines state what is wrong.
     */
    private function lockFileErrors(string $details): ?string
    {
        $errors = str($details)
            ->explode(PHP_EOL)
            ->map(static fn (string $line): string => trim($line))
            ->filter(static fn (string $line): bool => str_starts_with($line, '- '))
            ->map(static fn (string $line): string => (string) str($line)->before(', it is recommended')->finish('.'))
            ->implode(PHP_EOL);

        return $errors === '' ? null : $errors;
    }

    /**
     * Get the packages whose locked versions no longer satisfy composer.json.
     *
     * @return list<string>
     */
    private function mismatchedPackages(string $details): array
    {
        preg_match_all('/Required (?:dev )?package "([^"]+)"/', $details, $matches);

        return array_values(array_unique($matches[1]));
    }
}
