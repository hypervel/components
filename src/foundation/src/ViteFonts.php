<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use JsonException;

class ViteFonts
{
    /**
     * The cached font manifests.
     */
    protected static array $manifests = [];

    /**
     * Read the font manifest for the given configuration.
     *
     * @return null|array<string, mixed>
     *
     * @throws ViteException
     */
    public function manifest(bool $isHot, string $buildDirectory, string $manifestFilename, string $hotFile): ?array
    {
        $path = $isHot
            ? dirname($hotFile) . '/fonts-manifest.dev.json'
            : public_path($buildDirectory . '/' . $manifestFilename);

        return $this->readManifest($path);
    }

    /**
     * Read and decode a manifest file.
     *
     * @return null|array<string, mixed>
     *
     * @throws ViteException
     */
    protected function readManifest(string $path): ?array
    {
        if (isset(static::$manifests[$path])) {
            return static::$manifests[$path];
        }

        if (! is_file($path)) {
            return null;
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ViteException("Unable to read the font manifest at [{$path}].");
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ViteException("The font manifest at [{$path}] is not valid JSON.", previous: $exception);
        }

        if (! is_array($manifest)) {
            throw new ViteException("The font manifest at [{$path}] is invalid.");
        }

        return static::$manifests[$path] = $manifest;
    }

    /**
     * Resolve the CSS content from the manifest.
     *
     * @param array<string, mixed> $manifest
     * @param null|list<string> $aliases
     *
     * @throws ViteException
     */
    public function resolveStyleContent(array $manifest, ?array $aliases, string $buildDirectory): string
    {
        $style = $manifest['style'] ?? null;

        return match (true) {
            $style === null => '',
            $aliases !== null => $this->resolveFilteredStyleContent($style, $aliases),
            isset($style['inline']) => $style['inline'],
            isset($style['file']) => $this->readStyleFile($buildDirectory, $style['file']),
            default => '',
        };
    }

    /**
     * Resolve filtered CSS content using per-alias fragments from the manifest.
     *
     * @param array{inline?: string, file?: string, familyStyles?: array<string, string>, variables?: array<string, string>} $style
     * @param list<string> $aliases
     *
     * @throws ViteException
     */
    protected function resolveFilteredStyleContent(array $style, array $aliases): string
    {
        $familyStyles = $style['familyStyles'] ?? [];
        $variables = $style['variables'] ?? [];

        if (! is_array($familyStyles)) {
            throw new ViteException(
                'The font manifest [style.familyStyles] must be an object keyed by alias; the manifest was likely produced by an incompatible plugin version.'
            );
        }

        if (! is_array($variables)) {
            throw new ViteException(
                'The font manifest [style.variables] must be an object keyed by alias; the manifest was likely produced by an incompatible plugin version.'
            );
        }

        $parts = [];

        foreach ($aliases as $alias) {
            if (isset($familyStyles[$alias])) {
                $parts[] = $familyStyles[$alias];
            }
        }

        if ($variables !== []) {
            $parts[] = $this->filterVariables($variables, $aliases);
        }

        return implode("\n\n", $parts);
    }

    /**
     * Build a `:root` block containing only the CSS variables for the given aliases.
     *
     * @param array<string, string> $variables
     * @param list<string> $aliases list of aliases in desired emission order
     */
    protected function filterVariables(array $variables, array $aliases): string
    {
        $declarations = [];

        foreach ($aliases as $alias) {
            if (isset($variables[$alias])) {
                $declarations[] = '  ' . $variables[$alias];
            }
        }

        if ($declarations === []) {
            return '';
        }

        return ":root {\n" . implode("\n", $declarations) . "\n}";
    }

    /**
     * Read a CSS file from the build directory.
     *
     * @throws ViteException
     */
    protected function readStyleFile(string $buildDirectory, string $file): string
    {
        $path = public_path($buildDirectory . '/' . $file);

        if (! is_file($path)) {
            throw new ViteException("Unable to locate font CSS file from manifest: {$path}.");
        }

        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new ViteException("Unable to read font CSS file from manifest: {$path}.");
        }

        return $contents;
    }

    /**
     * Validate the font manifest structure.
     *
     * @param array<string, mixed> $manifest
     *
     * @throws ViteException
     */
    public function ensureValidManifest(array $manifest): void
    {
        if (! isset($manifest['version'])) {
            throw new ViteException('The font manifest is missing the [version] key.');
        }

        if ($manifest['version'] !== 1) {
            throw new ViteException("Unsupported font manifest version [{$manifest['version']}]. Supported versions: 1.");
        }

        if (! isset($manifest['families']) || ! is_array($manifest['families'])) {
            throw new ViteException('The font manifest is missing the [families] key.');
        }
    }

    /**
     * Validate that the requested aliases exist in the manifest.
     *
     * @param list<string> $aliases
     * @param array<string, mixed> $manifest
     *
     * @throws ViteException
     */
    public function ensureValidFamilies(array $aliases, array $manifest): void
    {
        $available = array_keys($manifest['families'] ?? []);

        foreach ($aliases as $alias) {
            if (! in_array($alias, $available, true)) {
                throw new ViteException(
                    "Font alias [{$alias}] is not defined in the font manifest. Available aliases: " . implode(', ', $available) . '.'
                );
            }
        }
    }

    /**
     * Validate that each preload entry contains the required keys.
     *
     * @param list<array<string, string>> $preloads
     *
     * @throws ViteException
     */
    public function ensureValidPreloads(array $preloads, bool $isHot): void
    {
        $urlKey = $isHot ? 'url' : 'file';

        foreach ($preloads as $index => $preload) {
            if (! isset($preload['alias'])) {
                throw new ViteException("Font manifest preload entry [{$index}] is missing the [alias] key.");
            }

            if (! isset($preload[$urlKey])) {
                throw new ViteException("Font manifest preload entry [{$index}] for alias [{$preload['alias']}] is missing the [{$urlKey}] key.");
            }
        }
    }

    /**
     * Flush cached manifests.
     */
    public static function flush(): void
    {
        static::$manifests = [];
    }
}
