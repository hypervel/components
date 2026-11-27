<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;

class OpcacheIsEnabledForCli extends Diagnostic
{
    public string $name = 'OPcache is enabled for CLI';

    public string $group = 'environment';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'local' => 'CLI OPcache is not required during development.',
            'enabled' => 'OPcache is enabled for the PHP CLI.',
            'not-loaded' => Message::make(
                summary: 'The Zend OPcache extension is not loaded in production.',
                remediation: 'Load the OPcache extension and set `opcache.enable=1` and `opcache.enable_cli=1` in your CLI `php.ini`, then restart the server.',
            )->link(Link::docs('deployment', 'php-configuration')),
            'disabled' => Message::make(
                summary: 'OPcache is not enabled for the PHP CLI in production.',
                remediation: 'Set `opcache.enable=1` and `opcache.enable_cli=1` in your CLI `php.ini`, then restart the server.',
            )->link(Link::docs('deployment', 'php-configuration')),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        if (! EnvironmentMode::current()->isProduction()) {
            return $this->skip('local');
        }

        if (! $this->opcacheLoaded()) {
            return $this->fail('not-loaded');
        }

        $enable = $this->iniFlag('opcache.enable');
        $enableCli = $this->iniFlag('opcache.enable_cli');

        if ($enable && $enableCli) {
            return $this->pass('enabled');
        }

        return $this->fail('disabled')
            ->withDetails(implode(PHP_EOL, [
                '- opcache.enable = ' . ($enable ? '1' : '0'),
                '- opcache.enable_cli = ' . ($enableCli ? '1' : '0'),
            ]));
    }

    /**
     * Determine whether the Zend OPcache extension is loaded.
     */
    protected function opcacheLoaded(): bool
    {
        return extension_loaded('Zend OPcache');
    }

    /**
     * Determine whether a boolean PHP INI directive is enabled.
     */
    protected function iniFlag(string $option): bool
    {
        return filter_var(ini_get($option), FILTER_VALIDATE_BOOL);
    }
}
