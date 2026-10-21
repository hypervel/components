<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Symfony\Component\Finder\Finder;
use Throwable;

class ConfigurationFilesCanBeLoaded extends Diagnostic
{
    public string $name = 'Config files load';

    public string $group = 'configuration';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'missing' => 'The application does not have configuration files.',
            'load-failed' => Message::make(
                summary: 'A configuration file could not be loaded.',
            )->link(Link::docs('configuration')),
            'loaded' => 'Configuration files can be loaded.',
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $path = config_path();

        if (! is_dir($path)) {
            return $this->skip('missing');
        }

        $files = Finder::create()->files()->name('*.php')->in($path)->sortByName();

        if (! $files->hasResults()) {
            return $this->skip('missing');
        }

        foreach ($files as $file) {
            try {
                require $file->getPathname();
            } catch (Throwable $e) {
                return $this->fail('load-failed')
                    ->withDetails(sprintf('%s: %s', $file->getRelativePathname(), $e->getMessage()));
            }
        }

        return $this->pass('loaded');
    }
}
