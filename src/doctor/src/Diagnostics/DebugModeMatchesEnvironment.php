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
use Hypervel\Support\Env;

class DebugModeMatchesEnvironment extends Diagnostic implements Fixable
{
    public string $name = 'Debug matches environment';

    public string $group = 'security';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'enabled-in-production' => Message::make(
                summary: 'Debug mode is enabled in production.',
                remediation: 'Set APP_DEBUG=false in production.',
                confirmation: 'Set APP_DEBUG=false in the application environment file?',
            )->link(Link::docs('configuration', 'debug-mode')),
            'matches' => 'Debug mode matches the application environment.',
            'already-disabled' => 'Debug mode is already disabled.',
            'disable-failed' => 'Debug mode could not be disabled in the application environment file.',
            'disabled' => 'Debug mode was disabled in the application environment file.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if ($this->debugIsEnabledInProduction()) {
            return $this->fail('enabled-in-production')->fixable();
        }

        return $this->pass('matches');
    }

    /**
     * Fix the diagnostic.
     */
    public function fix(DiagnosticResult $result, ?string $option = null): FixResult
    {
        if (! $this->debugIsEnabledInProduction()) {
            return $this->fixed('already-disabled');
        }

        $path = app()->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            return $this->fixFailed('disable-failed')
                ->withDetails(sprintf('The application environment file [%s] could not be updated.', $path));
        }

        Env::writeVariable('APP_DEBUG', 'false', $path, overwrite: true);

        config(['app.debug' => false]);

        return $this->fixed('disabled');
    }

    /**
     * Determine whether debug mode is enabled in production.
     */
    private function debugIsEnabledInProduction(): bool
    {
        return config()->boolean('app.debug') && EnvironmentMode::current()->isProduction();
    }
}
