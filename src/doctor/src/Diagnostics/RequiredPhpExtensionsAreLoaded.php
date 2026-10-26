<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Diagnostics;

use Composer\Semver\Constraint\ConstraintInterface;
use Composer\Semver\Semver;
use Composer\Semver\VersionParser;
use Hypervel\Doctor\Diagnostic;
use Hypervel\Doctor\Results\DiagnosticResult;
use Hypervel\Doctor\Results\Message;
use Hypervel\Doctor\Support\ComposerJson;
use Hypervel\Doctor\Support\InstalledPackages;
use UnexpectedValueException;

class RequiredPhpExtensionsAreLoaded extends Diagnostic
{
    public string $name = 'Required PHP extensions loaded';

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
            'composer-unreadable' => 'The application does not have a readable composer.json file.',
            'installed' => 'All Composer-required PHP extensions are satisfied by loaded extensions or installed providers.',
            'missing' => Message::make(
                summary: 'Some Composer-required PHP extensions are missing or incompatible.',
                remediation: 'Install compatible PHP extensions or providers for the PHP binary running Hypervel.',
            ),
        ];
    }

    /**
     * Run the diagnostic.
     */
    public function check(): DiagnosticResult
    {
        $packages = app(InstalledPackages::class);
        $platform = $packages->platformRequirements($this->composer);

        if ($platform === [] && $this->composer->contents() === null) {
            return $this->skip('composer-unreadable');
        }

        $loaded = $packages->loadedExtensions();
        $parser = new VersionParser;
        $missing = [];

        foreach ($platform as $extension => $metadata) {
            if (! str_starts_with($extension, 'ext-') || $metadata['requirements'] === []) {
                continue;
            }

            if ($this->satisfied($loaded[$extension] ?? null, $metadata['requirements'], $metadata['providers'], $parser)) {
                continue;
            }

            $requirements = [];

            foreach ($metadata['requirements'] as $package => $constraint) {
                $requirements[] = "{$package} requires {$constraint}";
            }

            $missing[] = sprintf('- %s (%s): %s.', $extension, isset($loaded[$extension]) ? 'loaded ' . $loaded[$extension] : 'not loaded', implode('; ', $requirements));
        }

        if ($missing === []) {
            return $this->pass('installed');
        }

        return $this->fail('missing')
            ->withDetails(implode(PHP_EOL, $missing));
    }

    /**
     * Determine whether a loaded extension or one provider satisfies every requirement.
     *
     * @param array<string, string> $requirements
     * @param array<string, string> $providers
     */
    private function satisfied(?string $version, array $requirements, array $providers, VersionParser $parser): bool
    {
        if ($version !== null) {
            $version = $this->normalizeVersion($version, $parser);

            if (array_all($requirements, static fn (string $constraint): bool => Semver::satisfies($version, $constraint))) {
                return true;
            }
        }

        $constraints = array_map($parser->parseConstraints(...), $requirements);

        foreach ($providers as $provider) {
            $candidate = $parser->parseConstraints($provider);

            if (array_all($constraints, static fn (ConstraintInterface $constraint): bool => $candidate->matches($constraint))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize extension versions using Composer's numeric fallback.
     */
    private function normalizeVersion(string $version, VersionParser $parser): string
    {
        try {
            return $parser->normalize($version);
        } catch (UnexpectedValueException) {
            return $parser->normalize(preg_match('/^(\d+\.\d+\.\d+(?:\.\d+)?)/', $version, $matches) === 1 ? $matches[1] : '0');
        }
    }
}
