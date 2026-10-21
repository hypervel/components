<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\ComposerJson;
use Hypervel\Doctor\Support\InstalledPackages;

class RecommendedPhpExtensionsAreLoaded extends Diagnostic
{
    public string $name = 'Recommended PHP extensions loaded';

    public string $group = 'environment';

    /**
     * Create a new diagnostic instance.
     */
    public function __construct(protected ComposerJson $composer)
    {
        parent::__construct();
    }

    /**
     * Get the diagnostic's named message definitions.
     *
     * @return array<string, Message|string>
     */
    protected function messages(): array
    {
        return [
            'none-declared' => 'No Composer-suggested PHP extensions are declared.',
            'installed' => 'All Composer-suggested PHP extensions are installed.',
            'missing' => Message::make(
                summary: 'Some Composer-suggested PHP extensions are missing.',
                remediation: 'Install the suggested extensions if the application uses the features that depend on them.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $extensions = $this->composer->suggestedExtensions();

        if ($extensions === []) {
            return $this->pass('none-declared');
        }

        $missing = $this->missingExtensions($extensions);

        if ($missing === []) {
            return $this->pass('installed');
        }

        return $this->warn('missing')
            ->withDetails($this->formatExtensions($missing));
    }

    /**
     * Get the missing PHP extensions.
     *
     * @param list<string> $extensions
     * @return list<string>
     */
    private function missingExtensions(array $extensions): array
    {
        $loaded = app(InstalledPackages::class)->loadedExtensions();

        return array_values(array_filter(
            $extensions,
            static fn (string $extension): bool => ! isset($loaded['ext-' . $extension]),
        ));
    }

    /**
     * Format extension names for diagnostic details.
     *
     * @param list<string> $extensions
     */
    private function formatExtensions(array $extensions): string
    {
        return implode(PHP_EOL, array_map(
            static fn (string $extension): string => '- ext-' . $extension,
            $extensions,
        ));
    }
}
