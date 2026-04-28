<?php

declare(strict_types=1);

namespace Hypervel\Inertia;

use Hypervel\Support\Facades\Facade;

/**
 * @method static void setRootView(string $name)
 * @method static void share(string|array<array-key, mixed>|\Hypervel\Contracts\Support\Arrayable<array-key, mixed>|\Hypervel\Inertia\ProvidesInertiaProperties $key, mixed $value = null)
 * @method static mixed getShared(string|null $key = null, mixed $default = null)
 * @method static void flushShared()
 * @method static void version(\Closure|string|null $version)
 * @method static string getVersion()
 * @method static void resolveUrlUsing(\Closure|null $urlResolver = null)
 * @method static void transformComponentUsing(\Closure|null $componentTransformer = null)
 * @method static void clearHistory()
 * @method static void preserveFragment()
 * @method static void encryptHistory(bool $encrypt = true)
 * @method static void disableSsr(\Closure|bool $condition = true)
 * @method static void withoutSsr(array<int, string>|string $paths)
 * @method static \Hypervel\Inertia\OptionalProp optional(callable $callback)
 * @method static \Hypervel\Inertia\DeferProp defer(callable $callback, string $group = 'default')
 * @method static \Hypervel\Inertia\MergeProp merge(mixed $value)
 * @method static \Hypervel\Inertia\MergeProp deepMerge(mixed $value)
 * @method static \Hypervel\Inertia\AlwaysProp always(mixed $value)
 * @method static \Hypervel\Inertia\ScrollProp<mixed> scroll(mixed $value, string $wrapper = 'data', \Hypervel\Inertia\ProvidesScrollMetadata|callable|null $metadata = null)
 * @method static \Hypervel\Inertia\OnceProp once(callable $value)
 * @method static \Hypervel\Inertia\OnceProp shareOnce(string $key, callable $callback)
 * @method static \Hypervel\Inertia\Response render(\BackedEnum|\UnitEnum|string $component, array<array-key, mixed>|\Hypervel\Contracts\Support\Arrayable<array-key, mixed>|\Hypervel\Inertia\ProvidesInertiaProperties $props = [])
 * @method static \Symfony\Component\HttpFoundation\Response location(string|\Symfony\Component\HttpFoundation\RedirectResponse $url)
 * @method static void handleExceptionsUsing(callable $callback)
 * @method static \Hypervel\Inertia\ResponseFactory flash(\BackedEnum|\UnitEnum|string|array<string, mixed> $key, mixed $value = null)
 * @method static \Symfony\Component\HttpFoundation\RedirectResponse back(int $status = 302, array<string, string> $headers = [], mixed $fallback = false)
 * @method static array<string, mixed> getFlashed(\Hypervel\Http\Request|null $request = null)
 * @method static array<string, mixed> pullFlashed(\Hypervel\Http\Request|null $request = null)
 * @method static void macro(string $name, object|callable $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \Hypervel\Inertia\ResponseFactory
 */
class Inertia extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ResponseFactory::class;
    }
}
