<?php

declare(strict_types=1);

namespace Hypervel\Head\Facades;

use BackedEnum;
use Hypervel\Head\HeadManager;
use Hypervel\Support\Facades\Facade;

/**
 * @method static \Hypervel\Head\HeadManager alternates(array<string, string> $alternates)
 * @method static \Hypervel\Head\HeadManager appleTouchIcon(string $href, string|null $sizes = null)
 * @method static \Hypervel\Head\HeadManager appleTouchStartupImage(string $href, \Hypervel\Head\Enums\Media|string|null $media = null)
 * @method static \Hypervel\Head\HeadManager appleWebAppStatusBarStyle(string $style)
 * @method static \Hypervel\Head\HeadManager appleWebAppTitle(string $title)
 * @method static \Hypervel\Head\HeadManager applicationName(string $name)
 * @method static \Hypervel\Head\HeadManager canonical(string|null $url = null, bool|null $forceHttps = null, bool|null $trailingSlash = null)
 * @method static \Hypervel\Head\HeadManager colorScheme(string $scheme)
 * @method static \Hypervel\Head\HeadManager description(string $description)
 * @method static \Hypervel\Head\HeadManager dnsPrefetch(string $href)
 * @method static \Hypervel\Head\HeadManager favicon(string $href, \Hypervel\Head\Enums\ImageType|string|null $type = null, string|null $sizes = null, \Hypervel\Head\Enums\Media|string|null $media = null)
 * @method static \Hypervel\Head\HeadManager feed(string $href, string $title, string $type = 'rss')
 * @method static \Hypervel\Head\HeadManager flush()
 * @method static void flushMacros()
 * @method static bool hasMacro(string $name)
 * @method static \Hypervel\Head\HeadManager hiddenFromRobots()
 * @method static \Hypervel\Head\HeadManager icon(string $href, \Hypervel\Head\Enums\ImageType|string|null $type = null, string|null $sizes = null, \Hypervel\Head\Enums\Media|string|null $media = null)
 * @method static \Hypervel\Head\HeadManager inertia(string $prop = 'head')
 * @method static string inertiaProp()
 * @method static \Hypervel\Head\HeadManager link(string $rel, string $href, array<string, null|BackedEnum|bool|float|int|string> $attributes = [])
 * @method static void macro(string $name, callable|object $macro)
 * @method static \Hypervel\Head\HeadManager manifest(string $href = '/site.webmanifest', string|bool|null $crossorigin = null)
 * @method static \Hypervel\Head\HeadManager maskIcon(string $href, string|null $color = null)
 * @method static \Hypervel\Head\HeadManager meta(string $key, string $content, bool|null $property = null, \Hypervel\Head\Enums\Media|string|null $media = null)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static \Hypervel\Head\HeadManager og(\Hypervel\Head\Enums\OgType|string|null $type = null, string|null $title = null, string|null $description = null, string|null $url = null, string|null $image = null, string|null $video = null, string|null $audio = null, string|null $siteName = null, string|null $locale = null, string|null $determiner = null)
 * @method static \Hypervel\Head\HeadManager ogAudio(string $url, string|null $type = null, string|null $secureUrl = null)
 * @method static \Hypervel\Head\HeadManager ogImage(string $url, string|null $alt = null, int|null $width = null, int|null $height = null, \Hypervel\Head\Enums\ImageType|string|null $type = null, string|null $secureUrl = null)
 * @method static \Hypervel\Head\HeadManager ogVideo(string $url, string|null $alt = null, int|null $width = null, int|null $height = null, string|null $type = null, string|null $secureUrl = null)
 * @method static \Hypervel\Head\HeadManager paginate(\Hypervel\Contracts\Pagination\Paginator<int, mixed> $paginator)
 * @method static \Hypervel\Head\HeadManager preconnect(string $href, string|bool|null $crossorigin = null)
 * @method static \Hypervel\Head\HeadManager prefetch(string $href, string|null $as = null)
 * @method static \Hypervel\Head\HeadManager prefetchAsset(string $path, string|null $as = null)
 * @method static \Hypervel\Head\HeadManager preload(string $href, string|null $as = null, string|bool|null $crossorigin = null, \Hypervel\Head\Enums\ImageType|string|null $type = null, \Hypervel\Head\Enums\Media|string|null $media = null)
 * @method static \Hypervel\Head\HeadManager preloadAsset(string $path, string|null $as = null, string|bool|null $crossorigin = null, \Hypervel\Head\Enums\ImageType|string|null $type = null, \Hypervel\Head\Enums\Media|string|null $media = null)
 * @method static \Hypervel\Head\HeadManager pwa(string $name, string $manifest = '/site.webmanifest', string|null $themeColor = null, string|null $appleTouchIcon = null, string|null $appleTouchIconSizes = '180x180', string|null $appleWebAppStatusBarStyle = null)
 * @method static \Hypervel\Head\HeadManager referrer(string $policy)
 * @method static \Hypervel\Support\HtmlString render(int|null $status = null)
 * @method static \Hypervel\Support\HtmlString renderForView(int|null $status = null)
 * @method static \Hypervel\Head\HeadManager robots(array<int, \Hypervel\Head\Enums\RobotsRule|string>|\Hypervel\Head\Enums\RobotsRule|string $directives)
 * @method static \Hypervel\Head\HeadManager searchableByRobots()
 * @method static \Hypervel\Head\HeadManager status(int $status)
 * @method static \Hypervel\Head\HeadManager themeColor(string $color, \Hypervel\Head\Enums\Media|string|null $media = null)
 * @method static \Hypervel\Head\HeadManager title(string $title, string|null $prefix = null, string|null $suffix = null, bool|null $exact = null)
 * @method static array<string, mixed> toArray(int|null $status = null)
 * @method static array<int, string> toElements(int|null $status = null)
 * @method static string toHtml(int|null $status = null)
 * @method static array<int, string> toInertiaElements(int|null $status = null)
 * @method static \Hypervel\Head\HeadManager twitter(\Hypervel\Head\Enums\TwitterCard|string|null $card = null, string|null $site = null, string|null $creator = null, string|null $title = null, string|null $description = null, string|null $image = null)
 * @method static \Hypervel\Head\HeadManager twitterImage(string $url, string|null $alt = null)
 * @method static \Hypervel\Head\HeadManager viewport(string $content)
 * @method static \Hypervel\Head\HeadManager webAppCapable(bool $capable = true)
 *
 * @see \Hypervel\Head\HeadManager
 *
 * @mixin HeadManager
 */
class Head extends Facade
{
    /**
     * Get methods that should be excluded from the generated facade docblock.
     *
     * The HeadManager mixin preserves callback, class-string and generic types
     * that generated method tags flatten.
     *
     * @return array<int, string>
     */
    protected static function ignoredFacadeDocumenterMethods(): array
    {
        return [
            'defaults',
            'errors',
            'inertiaGlobals',
            'resolveDefaultsUsing',
            'resolveErrorsUsing',
            'resolveCanonicalOriginUsing',
            'schema',
            'extend',
            'when',
            'unless',
        ];
    }

    /**
     * Get the registered name of the component.
     */
    protected static function getFacadeAccessor(): string
    {
        return 'head';
    }
}
