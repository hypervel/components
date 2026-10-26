<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Contracts\Process\ProcessResult;
use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Details;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Process;

class EnvironmentFileIsGitIgnored extends Diagnostic implements Fixable
{
    public string $name = '.env is gitignored';

    public string $group = 'security';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'gitignore-missing' => Message::make(
                summary: 'The environment file [{path}] is not ignored and has no adjacent .gitignore file.',
                remediation: 'Create [{gitignore}] with a rule for the selected environment file so secrets are not committed.',
                confirmation: 'Create [{gitignore}] to ignore [{path}]?',
            )->link(Link::docs('configuration', 'environment-configuration')),
            'git-missing' => 'Git is unavailable; ignoring of [{path}] could not be checked.',
            'not-repository' => 'The environment file [{path}] is outside a Git work tree.',
            'inspection-failed' => 'Git could not inspect the environment file [{path}].',
            'tracked' => Message::make(
                summary: 'The environment file [{path}] is tracked by Git.',
                remediation: 'Remove it from Git tracking with `git rm --cached -- <path>`, add an ignore rule, and rotate any exposed secrets.',
            )->link(Link::docs('configuration', 'environment-configuration')),
            'ignored' => 'The environment file [{path}] is ignored by Git.',
            'not-ignored' => Message::make(
                summary: 'The environment file [{path}] is not ignored by Git.',
                remediation: 'Add a rule for [{path}] to [{gitignore}] so secrets are not committed.',
                confirmation: 'Add an ignore rule for [{path}] to [{gitignore}]?',
            )->link(Link::docs('configuration', 'environment-configuration')),
            'already-ignored' => 'The environment file [{path}] is already ignored by Git.',
            'update-failed' => 'Git protection could not be established for [{path}].',
            'updated' => 'The environment file [{path}] is now ignored by Git.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $path = app()->environmentFilePath();
        $replace = ['path' => $path, 'gitignore' => $this->gitignorePath()];
        $process = $this->git(['rev-parse', '--is-inside-work-tree']);

        if ($process->exitCode() === 127) {
            return $this->skip('git-missing', $replace);
        }

        if (($process->exitCode() === 128 && str_contains($process->errorOutput(), 'not a git repository'))
            || ($process->successful() && trim($process->output()) === 'false')) {
            return $this->skip('not-repository', $replace);
        }

        if (! $process->successful() || trim($process->output()) !== 'true') {
            return $this->error('inspection-failed', $replace)->withDetails(Details::processOutput(
                $process->output(),
                $process->errorOutput(),
                'Git did not confirm work-tree membership.',
            ));
        }

        $process = $this->git(['--literal-pathspecs', 'ls-files', '--error-unmatch', '--', basename($path)]);

        if ($process->successful()) {
            return $this->fail('tracked', $replace);
        }

        if ($process->exitCode() !== 1) {
            return $this->error('inspection-failed', $replace)->withDetails(Details::processOutput(
                $process->output(),
                $process->errorOutput(),
                'Git could not determine whether the file is tracked.',
            ));
        }

        // Tracking was checked literally above; ignore Git's separate index pathspec matching.
        $process = $this->git(['check-ignore', '--no-index', '-q', '--', basename($path)]);

        if ($process->successful()) {
            return $this->pass('ignored', $replace);
        }

        if ($process->exitCode() !== 1) {
            return $this->error('inspection-failed', $replace)->withDetails(Details::processOutput(
                $process->output(),
                $process->errorOutput(),
                'Git could not determine whether the file is ignored.',
            ));
        }

        return $this->fail(File::exists($this->gitignorePath()) ? 'not-ignored' : 'gitignore-missing', $replace)
            ->fixable(EnvironmentMode::Local);
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        $path = $this->gitignorePath();
        $replace = ['path' => app()->environmentFilePath()];
        $current = $this->check();

        if ($current->code === $this->code('ignored')) {
            return $this->fixed('already-ignored', $replace);
        }

        if (! in_array($current->code, [$this->code('not-ignored'), $this->code('gitignore-missing')], true)) {
            return $this->fixFailed('update-failed', $replace)->withDetails($current->details ?? $current->summary);
        }

        $contents = File::exists($path) ? File::get($path) : '';
        $prefix = $contents !== '' && ! str_ends_with($contents, PHP_EOL) ? PHP_EOL : '';
        $pattern = '/' . strtr(basename(app()->environmentFilePath()), [
            '\\' => '\\\\', '*' => '\*', '?' => '\?', '[' => '\[',
        ]);

        if (File::put($path, $contents . $prefix . $pattern . PHP_EOL) === false) {
            return $this->fixFailed('update-failed', $replace);
        }

        $updated = $this->check();

        if ($updated->code !== $this->code('ignored')) {
            return $this->fixFailed('update-failed', $replace)->withDetails($updated->details ?? $updated->summary);
        }

        return $this->fixed('updated', $replace);
    }

    /**
     * Run Git beside the selected environment file.
     *
     * @param list<string> $arguments
     */
    private function git(array $arguments): ProcessResult
    {
        return Process::path(dirname(app()->environmentFilePath()))
            ->env(['LC_ALL' => 'C'])
            ->timeout(10)
            ->run(['git', ...$arguments]);
    }

    /**
     * Get the application .gitignore path.
     */
    private function gitignorePath(): string
    {
        return dirname(app()->environmentFilePath()) . '/.gitignore';
    }
}
