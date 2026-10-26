<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Message;
use Hypervel\Support\Str;

class BootstrapCacheMatchesEnvironment extends Diagnostic
{
    public string $name = 'Bootstrap cache matches environment';

    public string $group = 'configuration';

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'uncached-production' => Message::make(
                summary: 'The application bootstrap files are not cached.',
                remediation: 'Run `php artisan optimize` or `php artisan config:cache` during deployment.',
            )->link(Link::docs('deployment', 'optimization')),
            'uncached-local' => 'The application bootstrap files are not cached.',
            'cached-production' => 'The application bootstrap files are cached.',
            'partially-cached-production' => Message::make(
                summary: 'Cached bootstrap files: {cached}. Uncached bootstrap files: {uncached}.',
                remediation: 'Run `php artisan optimize` during deployment to cache the remaining bootstrap files.',
            )->link(Link::docs('deployment', 'optimization')),
            'cached-local' => Message::make(
                summary: 'Cached bootstrap {files} detected: {cached}.',
                remediation: 'If recent changes are not appearing, run `php artisan optimize:clear`.',
            )->link(Link::docs('deployment', 'optimization')),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $cached = $this->cachedFiles();

        if ($cached === []) {
            if (EnvironmentMode::current()->isProduction()) {
                return $this->warn('uncached-production');
            }

            return $this->pass('uncached-local');
        }

        if (EnvironmentMode::current()->isProduction()) {
            $uncached = array_values(array_diff(['config', 'events', 'routes'], $cached));

            if ($uncached !== []) {
                return $this->notice('partially-cached-production', [
                    'cached' => $this->formatCachedFiles($cached),
                    'uncached' => $this->formatCachedFiles($uncached),
                ]);
            }

            return $this->pass('cached-production');
        }

        return $this->notice('cached-local', [
            'files' => Str::plural('file', count($cached)),
            'cached' => $this->formatCachedFiles($cached),
        ]);
    }

    /**
     * Get the cached bootstrap file types.
     *
     * @return list<string>
     */
    private function cachedFiles(): array
    {
        $cached = [];

        if (is_file(app()->getCachedConfigPath())) {
            $cached[] = 'config';
        }

        if (is_file(app()->getCachedEventsPath())) {
            $cached[] = 'events';
        }

        if (is_file(app()->getCachedRoutesPath())) {
            $cached[] = 'routes';
        }

        return $cached;
    }

    /**
     * Format the cached bootstrap file types.
     *
     * @param list<string> $cached
     */
    private function formatCachedFiles(array $cached): string
    {
        return collect($cached)->join(', ', ' and ');
    }
}
