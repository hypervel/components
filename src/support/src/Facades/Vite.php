<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

/**
 * @method static string asset(string $asset, string|null $buildDirectory = null)
 * @method static string content(string $asset, string|null $buildDirectory = null)
 * @method static \Hypervel\Foundation\Vite createAssetPathsUsing(callable|null $resolver)
 * @method static string|null cspNonce()
 * @method static void flush()
 * @method static void flushMacros()
 * @method static \Hypervel\Support\HtmlString fonts(null|array<int, string>|string $aliases = null)
 * @method static bool hasMacro(string $name)
 * @method static string hotFile()
 * @method static bool isRunningHot()
 * @method static void macro(string $name, callable|object $macro)
 * @method static string|null manifestHash(string|null $buildDirectory = null)
 * @method static \Hypervel\Foundation\Vite mergeEntryPoints(array $entryPoints)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static \Hypervel\Foundation\Vite prefetch(int|null $concurrency = null, string $event = 'load')
 * @method static array preloadedAssets()
 * @method static \Hypervel\Support\HtmlString|null reactRefresh()
 * @method static string toHtml()
 * @method static \Hypervel\Foundation\Vite useAggressivePrefetching()
 * @method static \Hypervel\Foundation\Vite useBuildDirectory(string $path)
 * @method static string useCspNonce(string|null $nonce = null)
 * @method static \Hypervel\Foundation\Vite useFontsManifestFilename(string $filename)
 * @method static \Hypervel\Foundation\Vite useHotFile(string $path)
 * @method static \Hypervel\Foundation\Vite useIntegrityKey(string|false $key)
 * @method static \Hypervel\Foundation\Vite useManifestFilename(string $filename)
 * @method static \Hypervel\Foundation\Vite usePrefetchStrategy(string|null $strategy, array $config = [])
 * @method static \Hypervel\Foundation\Vite usePreloadTagAttributes(array|callable|false $attributes)
 * @method static \Hypervel\Foundation\Vite useScriptTagAttributes(array|callable $attributes)
 * @method static \Hypervel\Foundation\Vite useStyleTagAttributes(array|callable $attributes)
 * @method static \Hypervel\Foundation\Vite useWaterfallPrefetching(int|null $concurrency = null)
 * @method static \Hypervel\Foundation\Vite withEntryPoints(array $entryPoints)
 *
 * @see \Hypervel\Foundation\Vite
 */
class Vite extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Hypervel\Foundation\Vite::class;
    }
}
