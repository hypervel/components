<?php

declare(strict_types=1);

namespace Hypervel\Doctor\Support;

use Hypervel\Foundation\PackageManifest;
use Hypervel\Support\Facades\File;

class InstalledPackages
{
    /**
     * Get the application's and installed packages' platform requirements and providers.
     *
     * @return array<string, array{requirements: array<string, string>, providers: array<string, string>}>
     */
    public function platformRequirements(ComposerJson $composer): array
    {
        $vendorPath = $this->vendorPath();
        $path = $vendorPath . '/composer/installed.json';
        $installed = File::isFile($path) ? json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR) : [];
        $root = $composer->contents() ?? [];
        $packages = $installed['packages'] ?? [];
        $requirements = [];

        $this->collect($requirements, $root['name'] ?? 'root', $root['require'] ?? [], 'requirements');

        if (($installed['dev'] ?? false) === true) {
            $this->collect($requirements, ($root['name'] ?? 'root') . ' (require-dev)', $root['require-dev'] ?? [], 'requirements');
        }

        $rootProviders = [...($root['provide'] ?? []), ...($root['replace'] ?? [])];
        $rootVersion = $root['version'] ?? null;

        if (in_array('self.version', $rootProviders, true) && File::isFile($vendorPath . '/composer/installed.php')) {
            $metadata = require $vendorPath . '/composer/installed.php';
            $rootVersion = $metadata['root']['version'] ?? $rootVersion;
        }

        $this->collect($requirements, $root['name'] ?? 'root', $rootProviders, 'providers', $rootVersion);

        foreach ($packages as $package) {
            $this->collect($requirements, $package['name'], $package['require'] ?? [], 'requirements');
            $this->collect(
                $requirements,
                $package['name'],
                [...($package['provide'] ?? []), ...($package['replace'] ?? [])],
                'providers',
                $package['version_normalized'] ?? $package['version'] ?? null,
            );
        }

        return $requirements;
    }

    /**
     * Get loaded extensions by their Composer platform names.
     *
     * @return array<string, string>
     */
    public function loadedExtensions(): array
    {
        $loaded = [];

        foreach (get_loaded_extensions() as $extension) {
            if (! in_array($extension, ['Core', 'standard'], true)) {
                $loaded['ext-' . str_replace(' ', '-', strtolower($extension))] = phpversion($extension) ?: '0';
            }
        }

        return $loaded;
    }

    /**
     * Add platform links from one package to the snapshot.
     *
     * @param array<string, array{requirements: array<string, string>, providers: array<string, string>}> $requirements
     * @param array<string, string> $links
     * @param 'providers'|'requirements' $type
     */
    private function collect(array &$requirements, string $package, array $links, string $type, ?string $version = null): void
    {
        foreach ($links as $name => $constraint) {
            if ($name !== 'php' && ! str_starts_with($name, 'ext-')) {
                continue;
            }

            if ($constraint === 'self.version') {
                if ($version === null) {
                    continue;
                }

                $constraint = $version;
            }

            $requirements[$name] ??= ['requirements' => [], 'providers' => []];
            $requirements[$name][$type][$package] = $constraint;
        }
    }

    /**
     * Get the vendor directory used by framework package discovery.
     */
    protected function vendorPath(): string
    {
        return app(PackageManifest::class)->vendorPath;
    }
}
