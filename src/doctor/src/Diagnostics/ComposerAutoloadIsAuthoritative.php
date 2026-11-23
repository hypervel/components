<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Support\Composer;

class ComposerAutoloadIsAuthoritative extends Diagnostic
{
    public string $name = 'Composer autoload is authoritative';

    public string $group = 'composer';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'local' => 'Authoritative autoloading is not required during development.',
            'authoritative' => 'Composer uses an authoritative class map.',
            'not-authoritative' => Message::make(
                summary: 'Composer does not use an authoritative class map in production.',
                remediation: 'Build the release with `composer install --no-dev --classmap-authoritative`, then restart the workers.',
            )->link(Link::docs('deployment', 'autoloader-optimization')),
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

        return Composer::getLoader()->isClassMapAuthoritative()
            ? $this->pass('authoritative')
            : $this->fail('not-authoritative');
    }
}
