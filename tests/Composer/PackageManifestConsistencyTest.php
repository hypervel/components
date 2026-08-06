<?php

declare(strict_types=1);

namespace Hypervel\Tests\Composer;

use Hypervel\Tests\TestCase;
use JsonException;

/**
 * Enforce root-checkable Composer structures without constraining package-specific metadata.
 */
class PackageManifestConsistencyTest extends TestCase
{
    /**
     * Ensure split package requirements are declared consistently in the root manifest.
     *
     * @throws JsonException
     */
    public function testSplitPackageRequirementsAreDeclaredConsistentlyInRootManifest(): void
    {
        $rootComposer = $this->decodeManifest(__DIR__ . '/../../composer.json');

        foreach ($this->splitManifests() as $manifest) {
            $composer = $this->decodeManifest($manifest);
            $package = $composer['name'];

            foreach (['require', 'require-dev'] as $section) {
                foreach ($composer[$section] ?? [] as $dependency => $constraint) {
                    if ($dependency === 'php') {
                        continue;
                    }

                    $message = "Split package [{$package}] declares [{$dependency}] in [{$section}]";

                    if (str_starts_with($dependency, 'hypervel/')) {
                        $this->assertArrayHasKey($dependency, $rootComposer['replace'], $message);
                        $this->assertSame('self.version', $rootComposer['replace'][$dependency], $message);

                        continue;
                    }

                    $rootConstraint = $rootComposer['require'][$dependency]
                        ?? $rootComposer['require-dev'][$dependency]
                        ?? null;

                    $this->assertNotNull($rootConstraint, "{$message}, but it is absent from the root manifest.");
                    $this->assertSame($constraint, $rootConstraint, "{$message} with a different root constraint.");
                }
            }
        }
    }

    /**
     * Ensure every declared autoload path exists.
     *
     * @throws JsonException
     */
    public function testDeclaredAutoloadPathsExist(): void
    {
        foreach ([__DIR__ . '/../../composer.json', ...$this->splitManifests()] as $manifest) {
            $composer = $this->decodeManifest($manifest);
            $package = $composer['name'];

            foreach (['autoload', 'autoload-dev'] as $section) {
                foreach (['psr-4', 'files', 'classmap'] as $type) {
                    foreach ($composer[$section][$type] ?? [] as $paths) {
                        foreach ((array) $paths as $path) {
                            $resolvedPath = dirname($manifest) . '/' . $path;

                            $this->assertTrue(
                                file_exists($resolvedPath),
                                "Package [{$package}] declares missing autoload path [{$path}] in [{$section}.{$type}].",
                            );
                        }
                    }
                }
            }
        }
    }

    /**
     * Ensure split package support metadata matches the root manifest.
     *
     * @throws JsonException
     */
    public function testSplitPackageSupportMetadataMatchesRootManifest(): void
    {
        $rootComposer = $this->decodeManifest(__DIR__ . '/../../composer.json');

        foreach ($this->splitManifests() as $manifest) {
            $composer = $this->decodeManifest($manifest);

            $this->assertSame(
                $rootComposer['support'],
                $composer['support'] ?? null,
                "Split package [{$composer['name']}] has inconsistent support metadata.",
            );
        }
    }

    /**
     * Decode a Composer manifest.
     *
     * @return array<string, mixed>
     *
     * @throws JsonException
     */
    private function decodeManifest(string $path): array
    {
        return json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Return every split package manifest path.
     *
     * @return list<string>
     */
    private function splitManifests(): array
    {
        $manifests = glob(__DIR__ . '/../../src/*/composer.json');

        $this->assertNotFalse($manifests);

        return $manifests;
    }
}
