<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Composer;
use Hypervel\Foundation\PackageManifest;
use Hypervel\Support\Facades\Process;

class ComposerAutoloadIsValid extends Diagnostic implements Fixable
{
    public string $name = 'Composer autoload works';

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
            'dependencies-missing' => 'Composer dependencies are not installed.',
            'valid' => 'Composer can generate optimized autoload files.',
            'invalid' => Message::make(
                summary: 'Composer cannot generate valid autoload files.',
                remediation: 'Regenerate Composer autoload files with `composer dump-autoload`.',
                confirmation: 'Regenerate Composer autoload files using `composer dump-autoload`?',
            ),
            'regeneration-failed' => 'Composer autoload files could not be regenerated.',
            'regenerated' => 'Composer autoload files were regenerated.',
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

        if (! is_file(app(PackageManifest::class)->vendorPath . '/autoload.php')) {
            return $this->skip('dependencies-missing');
        }

        $process = Process::path(base_path())->run([
            ...Composer::command(),
            'dump-autoload',
            '--dry-run',
            '--no-scripts',
            '--optimize',
            '--strict-psr',
            '--strict-ambiguous',
            '--no-interaction',
        ]);

        if ($process->successful()) {
            return $this->pass('valid');
        }

        return $this->fail('invalid')
            ->fixable()
            ->withDetails(trim($process->errorOutput() !== '' ? $process->errorOutput() : $process->output()));
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        $process = Process::path(base_path())->forever()->run([
            ...Composer::command(),
            'dump-autoload',
            '--optimize',
            '--strict-psr',
            '--strict-ambiguous',
            '--no-interaction',
        ]);

        if (! $process->successful()) {
            return $this->fixFailed('regeneration-failed')
                ->withDetails(trim($process->errorOutput() !== '' ? $process->errorOutput() : $process->output()));
        }

        return $this->fixed('regenerated');
    }
}
