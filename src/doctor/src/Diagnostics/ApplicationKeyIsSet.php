<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Contracts\Fixable;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\FixResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\Details;
use Hypervel\Support\Env;
use Hypervel\Support\Facades\Artisan;

class ApplicationKeyIsSet extends Diagnostic implements Fixable
{
    public string $name = 'App key is set';

    public string $group = 'environment';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'configured' => 'The application key is configured.',
            'missing' => Message::make(
                summary: 'The application key is not configured.',
                remediation: 'Generate an application key with `php artisan key:generate`.',
                confirmation: 'Generate an application key?',
            )->link(Link::docs('encryption')),
            'generated' => 'The application key was generated.',
            'generation-failed' => 'The application key could not be generated.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if ($this->keyIsConfigured()) {
            return $this->pass('configured');
        }

        return $this->fail('missing')->fixable(EnvironmentMode::Local);
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        $path = app()->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            return $this->fixFailed('generation-failed')
                ->withDetails(sprintf('The application environment file [%s] could not be updated.', $path));
        }

        // key:generate can only replace an existing APP_KEY entry. This leaves
        // existing values untouched while adding the blank entry it requires.
        Env::writeVariable('APP_KEY', '', $path);

        Artisan::call('key:generate', ['--force' => true]);

        if (! $this->keyIsConfigured()) {
            return $this->fixFailed('generation-failed')
                ->withDetails(Details::processOutput(
                    Artisan::output(),
                    '',
                    'The key:generate command did not set an application key.',
                ));
        }

        return $this->fixed('generated');
    }

    /**
     * Determine whether the application key is configured.
     */
    private function keyIsConfigured(): bool
    {
        $key = config('app.key');

        return is_string($key) && trim($key) !== '';
    }
}
