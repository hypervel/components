<?php

declare(strict_types=1);

namespace Hypervel\Foundation;

use Exception;
use Hypervel\Context\Context;
use Hypervel\Support\Collection;
use Hypervel\Support\Contracts\Htmlable;
use Hypervel\Support\HtmlString;
use Hypervel\Support\Js;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use RuntimeException;

class Vite implements Htmlable
{
    use Macroable;

    /**
     * The Content Security Policy nonce context key.
     */
    protected const NONCE_CONTEXT_KEY = 'hypervel.vite.nonce';

    /**
     * The entry points context key.
     */
    protected const ENTRY_POINTS_CONTEXT_KEY = 'hypervel.vite.entry_points';

    /**
     * The preloaded assets context key.
     */
    protected const PRELOADED_ASSETS_CONTEXT_KEY = 'hypervel.vite.preloaded_assets';

    /**
     * The key to check for integrity hashes within the manifest.
     */
    protected string|false $integrityKey = 'integrity';

    /**
     * The path to the "hot" file.
     */
    protected ?string $hotFile = null;

    /**
     * The path to the build directory.
     */
    protected string $buildDirectory = 'build';

    /**
     * The name of the manifest file.
     */
    protected string $manifestFilename = 'manifest.json';

    /**
     * The custom asset path resolver.
     */
    protected $assetPathResolver;

    /**
     * The script tag attributes resolvers.
     */
    protected array $scriptTagAttributesResolvers = [];

    /**
     * The style tag attributes resolvers.
     */
    protected array $styleTagAttributesResolvers = [];

    /**
     * The preload tag attributes resolvers.
     */
    protected array $preloadTagAttributesResolvers = [];

    /**
     * The cached manifest files.
     */
    protected static array $manifests = [];

    /**
     * The prefetching strategy (waterfall or aggressive) to use.
     */
    protected ?string $prefetchStrategy = null;

    /**
     * The number of assets to load concurrently when using the "waterfall" strategy.
     */
    protected int $prefetchConcurrently = 3;

    /**
     * The name of the event that should trigger prefetching. The event must be dispatched on the `window`.
     */
    protected string $prefetchEvent = 'load';

    /**
     * Get the preloaded assets.
     */
    public function preloadedAssets(): array
    {
        return Context::get(static::PRELOADED_ASSETS_CONTEXT_KEY, []);
    }

    protected function setPreloadedAssets(array $preloadedAssets): void
    {
        Context::set($this::PRELOADED_ASSETS_CONTEXT_KEY, $preloadedAssets);
    }

    /**
     * Get the Content Security Policy nonce applied to all generated tags.
     */
    public function cspNonce(): ?string
    {
        return Context::get(static::NONCE_CONTEXT_KEY);
    }

    /**
     * Generate or set a Content Security Policy nonce to apply to all generated tags.
     */
    public function useCspNonce(?string $nonce = null): string
    {
        $nonce = $nonce ?? Str::random(40);
        Context::set(static::NONCE_CONTEXT_KEY, $nonce);

        return $nonce;
    }

    /**
     * Use the given key to detect integrity hashes in the manifest.
     */
    public function useIntegrityKey(string|false $key): static
    {
        $this->integrityKey = $key;

        return $this;
    }

    /**
     * Set the Vite entry points.
     */
    public function withEntryPoints(array $entryPoints): static
    {
        Context::set(static::ENTRY_POINTS_CONTEXT_KEY, $entryPoints);

        return $this;
    }

    /**
     * Merge additional Vite entry points with the current set.
     */
    public function mergeEntryPoints(array $entryPoints): static
    {
        $currentEntryPoints = Context::get(static::ENTRY_POINTS_CONTEXT_KEY, []);

        return $this->withEntryPoints(array_unique([
            ...$currentEntryPoints,
            ...$entryPoints,
        ]));
    }

    /**
     * Set the filename for the manifest file.
     */
    public function useManifestFilename(string $filename): static
    {
        $this->manifestFilename = $filename;

        return $this;
    }

    /**
     * Resolve asset paths using the provided resolver.
     */
    public function createAssetPathsUsing(?callable $resolver): static
    {
        $this->assetPathResolver = $resolver;

        return $this;
    }

    /**
     * Get the Vite "hot" file path.
     */
    public function hotFile(): string
    {
        return $this->hotFile ?? public_path('/hot');
    }

    /**
     * Set the Vite "hot" file path.
     */
    public function useHotFile(string $path): static
    {
        $this->hotFile = $path;

        return $this;
    }

    /**
     * Set the Vite build directory.
     */
    public function useBuildDirectory(string $path): static
    {
        $this->buildDirectory = $path;

        return $this;
    }

    /**
     * Use the given callback to resolve attributes for script tags.
     *
     * @param array|(callable(string, string, ?array, ?array): array) $attributes
     */
    public function useScriptTagAttributes(callable|array $attributes): static
    {
        if (! is_callable($attributes)) {
            $attributes = fn () => $attributes;
        }

        $this->scriptTagAttributesResolvers[] = $attributes;

        return $this;
    }

    /**
     * Use the given callback to resolve attributes for style tags.
     *
     * @param array|(callable(string, string, ?array, ?array): array) $attributes
     */
    public function useStyleTagAttributes(callable|array $attributes): static
    {
        if (! is_callable($attributes)) {
            $attributes = fn () => $attributes;
        }

        $this->styleTagAttributesResolvers[] = $attributes;

        return $this;
    }

    /**
     * Use the given callback to resolve attributes for preload tags.
     *
     * @param array|(callable(string, string, ?array, ?array): (array|false))|false $attributes
     */
    public function usePreloadTagAttributes(callable|array|false $attributes): static
    {
        if (! is_callable($attributes)) {
            $attributes = fn () => $attributes;
        }

        $this->preloadTagAttributesResolvers[] = $attributes;

        return $this;
    }

    /**
     * Eagerly prefetch assets.
     */
    public function prefetch(?int $concurrency = null, string $event = 'load'): static
    {
        $this->prefetchEvent = $event;

        return $concurrency === null
            ? $this->usePrefetchStrategy('aggressive')
            : $this->usePrefetchStrategy('waterfall', ['concurrency' => $concurrency]);
    }

    /**
     * Use the "waterfall" prefetching strategy.
     */
    public function useWaterfallPrefetching(?int $concurrency = null): static
    {
        return $this->usePrefetchStrategy('waterfall', [
            'concurrency' => $concurrency ?? $this->prefetchConcurrently,
        ]);
    }

    /**
     * Use the "aggressive" prefetching strategy.
     */
    public function useAggressivePrefetching(): static
    {
        return $this->usePrefetchStrategy('aggressive');
    }

    /**
     * Set the prefetching strategy.
     *
     * @throws Exception
     */
    public function usePrefetchStrategy(?string $strategy, array $config = []): static
    {
        $this->prefetchStrategy = $strategy;

        if ($strategy === 'waterfall') {
            $this->prefetchConcurrently = $config['concurrency'] ?? $this->prefetchConcurrently;
        }

        return $this;
    }

    /**
     * Generate Vite tags for an entrypoint.
     */
    public function __invoke(string|array $entrypoints, ?string $buildDirectory = null): HtmlString
    {
        $entrypoints = new Collection($entrypoints);
        $buildDirectory ??= $this->buildDirectory;

        if ($this->isRunningHot()) {
            return new HtmlString(
                $entrypoints
                    ->prepend('@vite/client')
                    ->map(fn ($entrypoint) => $this->makeTagForChunk($entrypoint, $this->hotAsset($entrypoint), null, null))
                    ->join('')
            );
        }

        $manifest = $this->manifest($buildDirectory);

        $tags = new Collection();
        $preloads = new Collection();

        foreach ($entrypoints as $entrypoint) {
            $chunk = $this->chunk($manifest, $entrypoint);

            $preloads->push([
                $chunk['src'],
                $this->assetPath("{$buildDirectory}/{$chunk['file']}"),
                $chunk,
                $manifest,
            ]);

            foreach ($chunk['imports'] ?? [] as $import) {
                $preloads->push([
                    $import,
                    $this->assetPath("{$buildDirectory}/{$manifest[$import]['file']}"),
                    $manifest[$import],
                    $manifest,
                ]);

                foreach ($manifest[$import]['css'] ?? [] as $css) {
                    $partialManifest = (new Collection($manifest))->where('file', $css);

                    $preloads->push([
                        $partialManifest->keys()->first(),
                        $this->assetPath("{$buildDirectory}/{$css}"),
                        $partialManifest->first(),
                        $manifest,
                    ]);

                    $tags->push($this->makeTagForChunk(
                        $partialManifest->keys()->first(),
                        $this->assetPath("{$buildDirectory}/{$css}"),
                        $partialManifest->first(),
                        $manifest
                    ));
                }
            }

            $tags->push($this->makeTagForChunk(
                $entrypoint,
                $this->assetPath("{$buildDirectory}/{$chunk['file']}"),
                $chunk,
                $manifest
            ));

            foreach ($chunk['css'] ?? [] as $css) {
                $partialManifest = (new Collection($manifest))->where('file', $css);

                $preloads->push([
                    $partialManifest->keys()->first(),
                    $this->assetPath("{$buildDirectory}/{$css}"),
                    $partialManifest->first(),
                    $manifest,
                ]);

                $tags->push($this->makeTagForChunk(
                    $partialManifest->keys()->first(),
                    $this->assetPath("{$buildDirectory}/{$css}"),
                    $partialManifest->first(),
                    $manifest
                ));
            }
        }

        [$stylesheets, $scripts] = $tags->unique()->partition(fn ($tag) => str_starts_with($tag, '<link'));

        $preloads = $preloads->unique()
            ->sortByDesc(fn ($args) => $this->isCssPath($args[1]))
            ->map(fn ($args) => $this->makePreloadTagForChunk(...$args));

        $base = $preloads->join('') . $stylesheets->join('') . $scripts->join('');

        /* @phpstan-ignore booleanOr.rightAlwaysFalse */
        if ($this->prefetchStrategy === null || $this->isRunningHot()) {
            return new HtmlString($base);
        }

        $discoveredImports = [];

        return (new Collection($entrypoints))
            ->flatMap(fn ($entrypoint) => (new Collection($manifest[$entrypoint]['dynamicImports'] ?? []))
                ->map(fn ($import) => $manifest[$import])
                ->filter(fn ($chunk) => str_ends_with($chunk['file'], '.js') || str_ends_with($chunk['file'], '.css'))
                ->flatMap($f = function ($chunk) use (&$f, $manifest, &$discoveredImports) {
                    return (new Collection([...$chunk['imports'] ?? [], ...$chunk['dynamicImports'] ?? []]))
                        ->reject(function ($import) use (&$discoveredImports) {
                            if (isset($discoveredImports[$import])) {
                                return true;
                            }

                            $discoveredImports[$import] = true;

                            return false;
                        })
                        ->reduce(
                            fn ($chunks, $import) => $chunks->merge(
                                $f($manifest[$import])
                            ),
                            new Collection([$chunk])
                        )
                        ->merge((new Collection($chunk['css'] ?? []))->map(
                            fn ($css) => (new Collection($manifest))->first(fn ($chunk) => $chunk['file'] === $css) ?? [
                                'file' => $css,
                            ],
                        ));
                })
                ->map(function ($chunk) use ($buildDirectory, $manifest) {
                    return (new Collection([
                        ...$this->resolvePreloadTagAttributes(
                            $chunk['src'] ?? null,
                            $url = $this->assetPath("{$buildDirectory}/{$chunk['file']}"),
                            $chunk,
                            $manifest,
                        ),
                        'rel' => 'prefetch',
                        'fetchpriority' => 'low',
                        'href' => $url,
                    ]))->reject(
                        fn ($value) => in_array($value, [null, false], true)
                    )->mapWithKeys(fn ($value, $key) => [
                        $key = (is_int($key) ? $value : $key) => $value === true ? $key : $value,
                    ])->all();
                })
                ->reject(function ($attributes) {
                    $preloadedAssets = $this->preloadedAssets();
                    return isset($preloadedAssets[$attributes['href']]);
                }))
            ->unique('href')
            ->values()
            ->pipe(fn ($assets) => with(Js::from($assets), fn ($assets) => match ($this->prefetchStrategy) {
                'waterfall' => new HtmlString($base . <<<HTML

                    <script{$this->nonceAttribute()}>
                         window.addEventListener('{$this->prefetchEvent}', () => window.setTimeout(() => {
                            const makeLink = (asset) => {
                                const link = document.createElement('link')

                                Object.keys(asset).forEach((attribute) => {
                                    link.setAttribute(attribute, asset[attribute])
                                })

                                return link
                            }

                            const loadNext = (assets, count) => window.setTimeout(() => {
                                if (count > assets.length) {
                                    count = assets.length

                                    if (count === 0) {
                                        return
                                    }
                                }

                                const fragment = new DocumentFragment

                                while (count > 0) {
                                    const link = makeLink(assets.shift())
                                    fragment.append(link)
                                    count--

                                    if (assets.length) {
                                        link.onload = () => loadNext(assets, 1)
                                        link.onerror = () => loadNext(assets, 1)
                                    }
                                }

                                document.head.append(fragment)
                            })

                            loadNext({$assets}, {$this->prefetchConcurrently})
                        }))
                    </script>
                    HTML),
                'aggressive' => new HtmlString($base . <<<HTML

                    <script{$this->nonceAttribute()}>
                         window.addEventListener('{$this->prefetchEvent}', () => window.setTimeout(() => {
                            const makeLink = (asset) => {
                                const link = document.createElement('link')

                                Object.keys(asset).forEach((attribute) => {
                                    link.setAttribute(attribute, asset[attribute])
                                })

                                return link
                            }

                            const fragment = new DocumentFragment;
                            {$assets}.forEach((asset) => fragment.append(makeLink(asset)))
                            document.head.append(fragment)
                         }))
                    </script>
                    HTML),
                default => throw new RuntimeException('Unknown prefetch strategy: ' . $this->prefetchStrategy),
            }));
    }

    /**
     * Make tag for the given chunk.
     */
    protected function makeTagForChunk(?string $src, string $url, ?array $chunk, ?array $manifest): string
    {
        if (
            $this->cspNonce() === null
            && $this->integrityKey !== false
            && ! array_key_exists($this->integrityKey, $chunk ?? [])
            && $this->scriptTagAttributesResolvers === []
            && $this->styleTagAttributesResolvers === []) {
            return $this->makeTag($url);
        }

        if ($this->isCssPath($url)) {
            return $this->makeStylesheetTagWithAttributes(
                $url,
                $this->resolveStylesheetTagAttributes($src, $url, $chunk, $manifest)
            );
        }

        return $this->makeScriptTagWithAttributes(
            $url,
            $this->resolveScriptTagAttributes($src, $url, $chunk, $manifest)
        );
    }

    /**
     * Make a preload tag for the given chunk.
     */
    protected function makePreloadTagForChunk(?string $src, string $url, ?array $chunk, array $manifest): string
    {
        $attributes = $this->resolvePreloadTagAttributes($src, $url, $chunk, $manifest);

        if ($attributes === false) {
            return '';
        }

        $preloadedAssets = $this->preloadedAssets();
        $preloadedAssets[$url] = $this->parseAttributes(
            (new Collection($attributes))->forget('href')->all()
        );
        $this->setPreloadedAssets($preloadedAssets);

        return '<link ' . implode(' ', $this->parseAttributes($attributes)) . ' />';
    }

    /**
     * Resolve the attributes for the chunks generated script tag.
     */
    protected function resolveScriptTagAttributes(?string $src, string $url, ?array $chunk, ?array $manifest): array
    {
        $attributes = $this->integrityKey !== false
            ? ['integrity' => $chunk[$this->integrityKey] ?? false]
            : [];

        foreach ($this->scriptTagAttributesResolvers as $resolver) {
            $attributes = array_merge($attributes, $resolver($src, $url, $chunk, $manifest));
        }

        return $attributes;
    }

    /**
     * Resolve the attributes for the chunks generated stylesheet tag.
     */
    protected function resolveStylesheetTagAttributes(?string $src, string $url, ?array $chunk, ?array $manifest): array
    {
        $attributes = $this->integrityKey !== false
            ? ['integrity' => $chunk[$this->integrityKey] ?? false]
            : [];

        foreach ($this->styleTagAttributesResolvers as $resolver) {
            $attributes = array_merge($attributes, $resolver($src, $url, $chunk, $manifest));
        }

        return $attributes;
    }

    /**
     * Resolve the attributes for the chunks generated preload tag.
     */
    protected function resolvePreloadTagAttributes(?string $src, string $url, ?array $chunk, array $manifest): array|false
    {
        $attributes = $this->isCssPath($url) ? [
            'rel' => 'preload',
            'as' => 'style',
            'href' => $url,
            'nonce' => $this->cspNonce() ?? false,
            'crossorigin' => $this->resolveStylesheetTagAttributes($src, $url, $chunk, $manifest)['crossorigin'] ?? false,
        ] : [
            'rel' => 'modulepreload',
            'href' => $url,
            'nonce' => $this->cspNonce() ?? false,
            'crossorigin' => $this->resolveScriptTagAttributes($src, $url, $chunk, $manifest)['crossorigin'] ?? false,
        ];

        $attributes = $this->integrityKey !== false
            ? array_merge($attributes, ['integrity' => $chunk[$this->integrityKey] ?? false])
            : $attributes;

        foreach ($this->preloadTagAttributesResolvers as $resolver) {
            if (false === ($resolvedAttributes = $resolver($src, $url, $chunk, $manifest))) {
                return false;
            }

            $attributes = array_merge($attributes, $resolvedAttributes);
        }

        return $attributes;
    }

    /**
     * Generate an appropriate tag for the given URL in HMR mode.
     *
     * @deprecated will be removed in a future Laravel version
     */
    protected function makeTag(string $url): string
    {
        if ($this->isCssPath($url)) {
            return $this->makeStylesheetTag($url);
        }

        return $this->makeScriptTag($url);
    }

    /**
     * Generate a script tag for the given URL.
     *
     * @deprecated will be removed in a future Laravel version
     */
    protected function makeScriptTag(string $url): string
    {
        return $this->makeScriptTagWithAttributes($url, []);
    }

    /**
     * Generate a stylesheet tag for the given URL in HMR mode.
     *
     * @deprecated will be removed in a future Laravel version
     */
    protected function makeStylesheetTag(string $url): string
    {
        return $this->makeStylesheetTagWithAttributes($url, []);
    }

    /**
     * Generate a script tag with attributes for the given URL.
     */
    protected function makeScriptTagWithAttributes(string $url, array $attributes): string
    {
        $attributes = $this->parseAttributes(array_merge([
            'type' => 'module',
            'src' => $url,
            'nonce' => $this->cspNonce() ?? false,
        ], $attributes));

        return '<script ' . implode(' ', $attributes) . '></script>';
    }

    /**
     * Generate a link tag with attributes for the given URL.
     */
    protected function makeStylesheetTagWithAttributes(string $url, array $attributes): string
    {
        $attributes = $this->parseAttributes(array_merge([
            'rel' => 'stylesheet',
            'href' => $url,
            'nonce' => $this->cspNonce() ?? false,
        ], $attributes));

        return '<link ' . implode(' ', $attributes) . ' />';
    }

    /**
     * Determine whether the given path is a CSS file.
     */
    protected function isCssPath(string $path): bool
    {
        return preg_match('/\.(css|less|sass|scss|styl|stylus|pcss|postcss)(\?[^\.]*)?$/', $path) === 1;
    }

    /**
     * Parse the attributes into key="value" strings.
     */
    protected function parseAttributes(array $attributes): array
    {
        return (new Collection($attributes))
            ->reject(fn ($value, $key) => in_array($value, [false, null], true))
            ->flatMap(fn ($value, $key) => $value === true ? [$key] : [$key => $value])
            ->map(fn ($value, $key) => is_int($key) ? $value : $key . '="' . $value . '"')
            ->values()
            ->all();
    }

    /**
     * Generate React refresh runtime script.
     */
    public function reactRefresh(): ?HtmlString
    {
        if (! $this->isRunningHot()) {
            return null;
        }

        $attributes = $this->parseAttributes([
            'nonce' => $this->cspNonce(),
        ]);

        return new HtmlString(
            sprintf(
                <<<'HTML'
                <script type="module" %s>
                    import RefreshRuntime from '%s'
                    RefreshRuntime.injectIntoGlobalHook(window)
                    window.$RefreshReg$ = () => {}
                    window.$RefreshSig$ = () => (type) => type
                    window.__vite_plugin_react_preamble_installed__ = true
                </script>
                HTML,
                implode(' ', $attributes),
                $this->hotAsset('@react-refresh')
            )
        );
    }

    /**
     * Get the path to a given asset when running in HMR mode.
     */
    protected function hotAsset(string $asset): string
    {
        return rtrim(file_get_contents($this->hotFile())) . '/' . $asset;
    }

    /**
     * Get the URL for an asset.
     */
    public function asset(string $asset, ?string $buildDirectory = null): string
    {
        $buildDirectory ??= $this->buildDirectory;

        if ($this->isRunningHot()) {
            return $this->hotAsset($asset);
        }

        $chunk = $this->chunk($this->manifest($buildDirectory), $asset);

        return $this->assetPath($buildDirectory . '/' . $chunk['file']);
    }

    /**
     * Get the content of a given asset.
     *
     * @throws ViteException
     */
    public function content(string $asset, ?string $buildDirectory = null): string
    {
        $buildDirectory ??= $this->buildDirectory;

        $chunk = $this->chunk($this->manifest($buildDirectory), $asset);

        $path = public_path($buildDirectory . '/' . $chunk['file']);

        if (! is_file($path) || ! file_exists($path)) {
            throw new ViteException("Unable to locate file from Vite manifest: {$path}.");
        }

        return file_get_contents($path);
    }

    /**
     * Generate an asset path for the application.
     */
    protected function assetPath(string $path, ?bool $secure = null): string
    {
        return ($this->assetPathResolver ?? asset(...))($path, $secure);
    }

    /**
     * Get the manifest file for the given build directory.
     *
     * @throws ViteException
     */
    protected function manifest(string $buildDirectory): array
    {
        $path = $this->manifestPath($buildDirectory);

        if (! isset(static::$manifests[$path])) {
            if (! is_file($path)) {
                throw new ViteException("Vite manifest not found at: {$path}");
            }

            static::$manifests[$path] = json_decode(file_get_contents($path), true);
        }

        return static::$manifests[$path];
    }

    /**
     * Get the path to the manifest file for the given build directory.
     */
    protected function manifestPath(string $buildDirectory): string
    {
        return public_path($buildDirectory . '/' . $this->manifestFilename);
    }

    /**
     * Get a unique hash representing the current manifest, or null if there is no manifest.
     */
    public function manifestHash(?string $buildDirectory = null): ?string
    {
        $buildDirectory ??= $this->buildDirectory;

        if ($this->isRunningHot()) {
            return null;
        }

        if (! is_file($path = $this->manifestPath($buildDirectory))) {
            return null;
        }

        return md5_file($path) ?: null;
    }

    /**
     * Get the chunk for the given entry point / asset.
     *
     * @throws ViteException
     */
    protected function chunk(array $manifest, string $file): array
    {
        if (! isset($manifest[$file])) {
            throw new ViteException("Unable to locate file in Vite manifest: {$file}.");
        }

        return $manifest[$file];
    }

    /**
     * Get the nonce attribute for the prefetch script tags.
     */
    protected function nonceAttribute(): HtmlString
    {
        if ($this->cspNonce() === null) {
            return new HtmlString('');
        }

        return new HtmlString(' nonce="' . $this->cspNonce() . '"');
    }

    /**
     * Determine if the HMR server is running.
     */
    public function isRunningHot(): bool
    {
        return is_file($this->hotFile());
    }

    /**
     * Get the Vite tag content as a string of HTML.
     */
    public function toHtml(): string
    {
        $entryPoints = Context::get(static::ENTRY_POINTS_CONTEXT_KEY, []);

        return $this->__invoke($entryPoints)->toHtml();
    }
}
