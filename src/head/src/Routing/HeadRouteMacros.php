<?php

declare(strict_types=1);

namespace Hypervel\Head\Routing;

use Closure;
use Hypervel\Head\Enums\RobotsRule;
use Hypervel\Routing\PendingResourceRegistration;
use Hypervel\Routing\PendingSingletonResourceRegistration;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Routing\RouteRegistrar;

/**
 * Registers the withHead() route macros.
 *
 * Each macro carries the full named-argument signature for the built-in
 * route attributes so editors and static analysis can validate attribute
 * names at every chain position; keep the five signatures, _ide_helper.php,
 * and PHPStan's RoutingMacros signature in sync when adding route attributes.
 * The macros delegate to Hypervel's native route metadata API immediately,
 * so routes keep storing plain, cache-friendly arrays.
 */
class HeadRouteMacros
{
    /**
     * Register the withHead() macro on every routing surface.
     *
     * Boot-only. These static macros affect every request in the worker.
     */
    public function register(): void
    {
        Router::macro('withHead', $this->routerMacro());
        RouteRegistrar::macro('withHead', $this->registrarMacro());
        Route::macro('withHead', $this->routeMacro());
        PendingResourceRegistration::macro('withHead', $this->pendingResourceMacro());
        PendingSingletonResourceRegistration::macro('withHead', $this->pendingSingletonMacro());
    }

    /**
     * Recover the typed routing object a macro closure was bound to.
     *
     * Macroable rebinds each macro closure at call time, so inside the
     * closure $this is the routing object rather than this registrar.
     *
     * @template TScope of object
     *
     * @param class-string<TScope> $scope
     * @return TScope
     */
    public static function bound(mixed $context, string $scope): object
    {
        /** @var TScope $context */
        return $context;
    }

    /**
     * Create the withHead() macro for group-position calls on the router,
     * such as Route::withHead(...)->prefix('admin')->group(...).
     */
    protected function routerMacro(): Closure
    {
        /**
         * @param null|array<mixed, mixed>|string $title
         * @param null|array<mixed, mixed>|string|true $canonical
         * @param null|array<int, RobotsRule|string>|RobotsRule|string $robots
         * @param null|array<string, mixed> $og
         * @param null|array<mixed, mixed>|string $ogImage
         * @param null|array<mixed, mixed>|string $ogVideo
         * @param null|array<mixed, mixed>|string $ogAudio
         * @param null|array<string, mixed> $twitter
         * @param null|array<mixed, mixed>|string $twitterImage
         * @param null|array<mixed, mixed>|string $preload
         * @param null|array<mixed, mixed>|string $prefetch
         * @param null|array<mixed, mixed>|string $preconnect
         * @param null|array<mixed, mixed>|string $dnsPrefetch
         * @param null|array<string, string> $alternates
         * @param null|array<mixed, mixed>|string $feed
         * @param null|array<mixed, mixed>|string $icon
         * @param null|array<mixed, mixed>|string $favicon
         * @param null|array<mixed, mixed>|string $appleTouchIcon
         * @param null|array<mixed, mixed>|string $appleTouchStartupImage
         * @param null|array<mixed, mixed>|string $maskIcon
         * @param null|array<mixed, mixed>|string $manifest
         * @param null|array<mixed, mixed> $schema
         * @param null|array<mixed, mixed> $meta
         * @param null|array<mixed, mixed> $link
         * @param array<string, mixed> $extensions
         */
        $macro = function (
            string|array|null $title = null,
            ?string $description = null,
            string|array|true|null $canonical = null,
            string|RobotsRule|array|null $robots = null,
            ?string $themeColor = null,
            ?string $applicationName = null,
            ?string $colorScheme = null,
            ?string $referrer = null,
            ?string $viewport = null,
            ?string $appleWebAppTitle = null,
            ?bool $webAppCapable = null,
            ?string $appleWebAppStatusBarStyle = null,
            ?array $og = null,
            string|array|null $ogImage = null,
            string|array|null $ogVideo = null,
            string|array|null $ogAudio = null,
            ?array $twitter = null,
            string|array|null $twitterImage = null,
            string|array|null $preload = null,
            string|array|null $prefetch = null,
            string|array|null $preconnect = null,
            string|array|null $dnsPrefetch = null,
            ?array $alternates = null,
            string|array|null $feed = null,
            string|array|null $icon = null,
            string|array|null $favicon = null,
            string|array|null $appleTouchIcon = null,
            string|array|null $appleTouchStartupImage = null,
            string|array|null $maskIcon = null,
            string|array|null $manifest = null,
            ?array $schema = null,
            ?array $meta = null,
            ?array $link = null,
            array $extensions = [],
        ): RouteRegistrar {
            $arguments = get_defined_vars();

            $router = HeadRouteMacros::bound($this, Router::class);

            return (new RouteRegistrar($router))->metadata(
                RouteAttributeParser::metadataFromArguments($arguments)
            );
        };

        return $macro;
    }

    /**
     * Create the withHead() macro for chained group-position calls, such as
     * Route::prefix('admin')->withHead(...)->group(...).
     */
    protected function registrarMacro(): Closure
    {
        /**
         * @param null|array<mixed, mixed>|string $title
         * @param null|array<mixed, mixed>|string|true $canonical
         * @param null|array<int, RobotsRule|string>|RobotsRule|string $robots
         * @param null|array<string, mixed> $og
         * @param null|array<mixed, mixed>|string $ogImage
         * @param null|array<mixed, mixed>|string $ogVideo
         * @param null|array<mixed, mixed>|string $ogAudio
         * @param null|array<string, mixed> $twitter
         * @param null|array<mixed, mixed>|string $twitterImage
         * @param null|array<mixed, mixed>|string $preload
         * @param null|array<mixed, mixed>|string $prefetch
         * @param null|array<mixed, mixed>|string $preconnect
         * @param null|array<mixed, mixed>|string $dnsPrefetch
         * @param null|array<string, string> $alternates
         * @param null|array<mixed, mixed>|string $feed
         * @param null|array<mixed, mixed>|string $icon
         * @param null|array<mixed, mixed>|string $favicon
         * @param null|array<mixed, mixed>|string $appleTouchIcon
         * @param null|array<mixed, mixed>|string $appleTouchStartupImage
         * @param null|array<mixed, mixed>|string $maskIcon
         * @param null|array<mixed, mixed>|string $manifest
         * @param null|array<mixed, mixed> $schema
         * @param null|array<mixed, mixed> $meta
         * @param null|array<mixed, mixed> $link
         * @param array<string, mixed> $extensions
         */
        $macro = function (
            string|array|null $title = null,
            ?string $description = null,
            string|array|true|null $canonical = null,
            string|RobotsRule|array|null $robots = null,
            ?string $themeColor = null,
            ?string $applicationName = null,
            ?string $colorScheme = null,
            ?string $referrer = null,
            ?string $viewport = null,
            ?string $appleWebAppTitle = null,
            ?bool $webAppCapable = null,
            ?string $appleWebAppStatusBarStyle = null,
            ?array $og = null,
            string|array|null $ogImage = null,
            string|array|null $ogVideo = null,
            string|array|null $ogAudio = null,
            ?array $twitter = null,
            string|array|null $twitterImage = null,
            string|array|null $preload = null,
            string|array|null $prefetch = null,
            string|array|null $preconnect = null,
            string|array|null $dnsPrefetch = null,
            ?array $alternates = null,
            string|array|null $feed = null,
            string|array|null $icon = null,
            string|array|null $favicon = null,
            string|array|null $appleTouchIcon = null,
            string|array|null $appleTouchStartupImage = null,
            string|array|null $maskIcon = null,
            string|array|null $manifest = null,
            ?array $schema = null,
            ?array $meta = null,
            ?array $link = null,
            array $extensions = [],
        ): RouteRegistrar {
            $arguments = get_defined_vars();

            $registrar = HeadRouteMacros::bound($this, RouteRegistrar::class);

            return $registrar->metadata(
                RouteAttributeParser::metadataFromArguments($arguments)
            );
        };

        return $macro;
    }

    /**
     * Create the withHead() macro for individual routes, such as
     * Route::view('/dashboard', 'dashboard')->withHead(...).
     */
    protected function routeMacro(): Closure
    {
        /**
         * @param null|array<mixed, mixed>|string $title
         * @param null|array<mixed, mixed>|string|true $canonical
         * @param null|array<int, RobotsRule|string>|RobotsRule|string $robots
         * @param null|array<string, mixed> $og
         * @param null|array<mixed, mixed>|string $ogImage
         * @param null|array<mixed, mixed>|string $ogVideo
         * @param null|array<mixed, mixed>|string $ogAudio
         * @param null|array<string, mixed> $twitter
         * @param null|array<mixed, mixed>|string $twitterImage
         * @param null|array<mixed, mixed>|string $preload
         * @param null|array<mixed, mixed>|string $prefetch
         * @param null|array<mixed, mixed>|string $preconnect
         * @param null|array<mixed, mixed>|string $dnsPrefetch
         * @param null|array<string, string> $alternates
         * @param null|array<mixed, mixed>|string $feed
         * @param null|array<mixed, mixed>|string $icon
         * @param null|array<mixed, mixed>|string $favicon
         * @param null|array<mixed, mixed>|string $appleTouchIcon
         * @param null|array<mixed, mixed>|string $appleTouchStartupImage
         * @param null|array<mixed, mixed>|string $maskIcon
         * @param null|array<mixed, mixed>|string $manifest
         * @param null|array<mixed, mixed> $schema
         * @param null|array<mixed, mixed> $meta
         * @param null|array<mixed, mixed> $link
         * @param array<string, mixed> $extensions
         */
        $macro = function (
            string|array|null $title = null,
            ?string $description = null,
            string|array|true|null $canonical = null,
            string|RobotsRule|array|null $robots = null,
            ?string $themeColor = null,
            ?string $applicationName = null,
            ?string $colorScheme = null,
            ?string $referrer = null,
            ?string $viewport = null,
            ?string $appleWebAppTitle = null,
            ?bool $webAppCapable = null,
            ?string $appleWebAppStatusBarStyle = null,
            ?array $og = null,
            string|array|null $ogImage = null,
            string|array|null $ogVideo = null,
            string|array|null $ogAudio = null,
            ?array $twitter = null,
            string|array|null $twitterImage = null,
            string|array|null $preload = null,
            string|array|null $prefetch = null,
            string|array|null $preconnect = null,
            string|array|null $dnsPrefetch = null,
            ?array $alternates = null,
            string|array|null $feed = null,
            string|array|null $icon = null,
            string|array|null $favicon = null,
            string|array|null $appleTouchIcon = null,
            string|array|null $appleTouchStartupImage = null,
            string|array|null $maskIcon = null,
            string|array|null $manifest = null,
            ?array $schema = null,
            ?array $meta = null,
            ?array $link = null,
            array $extensions = [],
        ): Route {
            $arguments = get_defined_vars();

            $route = HeadRouteMacros::bound($this, Route::class);

            return $route->metadata(
                RouteAttributeParser::metadataFromArguments($arguments)
            );
        };

        return $macro;
    }

    /**
     * Create the withHead() macro for resource registrations, such as
     * Route::resource('posts', PostController::class)->withHead(...).
     */
    protected function pendingResourceMacro(): Closure
    {
        /**
         * @param null|array<mixed, mixed>|string $title
         * @param null|array<mixed, mixed>|string|true $canonical
         * @param null|array<int, RobotsRule|string>|RobotsRule|string $robots
         * @param null|array<string, mixed> $og
         * @param null|array<mixed, mixed>|string $ogImage
         * @param null|array<mixed, mixed>|string $ogVideo
         * @param null|array<mixed, mixed>|string $ogAudio
         * @param null|array<string, mixed> $twitter
         * @param null|array<mixed, mixed>|string $twitterImage
         * @param null|array<mixed, mixed>|string $preload
         * @param null|array<mixed, mixed>|string $prefetch
         * @param null|array<mixed, mixed>|string $preconnect
         * @param null|array<mixed, mixed>|string $dnsPrefetch
         * @param null|array<string, string> $alternates
         * @param null|array<mixed, mixed>|string $feed
         * @param null|array<mixed, mixed>|string $icon
         * @param null|array<mixed, mixed>|string $favicon
         * @param null|array<mixed, mixed>|string $appleTouchIcon
         * @param null|array<mixed, mixed>|string $appleTouchStartupImage
         * @param null|array<mixed, mixed>|string $maskIcon
         * @param null|array<mixed, mixed>|string $manifest
         * @param null|array<mixed, mixed> $schema
         * @param null|array<mixed, mixed> $meta
         * @param null|array<mixed, mixed> $link
         * @param array<string, mixed> $extensions
         */
        $macro = function (
            string|array|null $title = null,
            ?string $description = null,
            string|array|true|null $canonical = null,
            string|RobotsRule|array|null $robots = null,
            ?string $themeColor = null,
            ?string $applicationName = null,
            ?string $colorScheme = null,
            ?string $referrer = null,
            ?string $viewport = null,
            ?string $appleWebAppTitle = null,
            ?bool $webAppCapable = null,
            ?string $appleWebAppStatusBarStyle = null,
            ?array $og = null,
            string|array|null $ogImage = null,
            string|array|null $ogVideo = null,
            string|array|null $ogAudio = null,
            ?array $twitter = null,
            string|array|null $twitterImage = null,
            string|array|null $preload = null,
            string|array|null $prefetch = null,
            string|array|null $preconnect = null,
            string|array|null $dnsPrefetch = null,
            ?array $alternates = null,
            string|array|null $feed = null,
            string|array|null $icon = null,
            string|array|null $favicon = null,
            string|array|null $appleTouchIcon = null,
            string|array|null $appleTouchStartupImage = null,
            string|array|null $maskIcon = null,
            string|array|null $manifest = null,
            ?array $schema = null,
            ?array $meta = null,
            ?array $link = null,
            array $extensions = [],
        ): PendingResourceRegistration {
            $arguments = get_defined_vars();

            $registration = HeadRouteMacros::bound($this, PendingResourceRegistration::class);

            return $registration->metadata(
                RouteAttributeParser::metadataFromArguments($arguments)
            );
        };

        return $macro;
    }

    /**
     * Create the withHead() macro for singleton resource registrations, such
     * as Route::singleton('profile', ProfileController::class)->withHead(...).
     */
    protected function pendingSingletonMacro(): Closure
    {
        /**
         * @param null|array<mixed, mixed>|string $title
         * @param null|array<mixed, mixed>|string|true $canonical
         * @param null|array<int, RobotsRule|string>|RobotsRule|string $robots
         * @param null|array<string, mixed> $og
         * @param null|array<mixed, mixed>|string $ogImage
         * @param null|array<mixed, mixed>|string $ogVideo
         * @param null|array<mixed, mixed>|string $ogAudio
         * @param null|array<string, mixed> $twitter
         * @param null|array<mixed, mixed>|string $twitterImage
         * @param null|array<mixed, mixed>|string $preload
         * @param null|array<mixed, mixed>|string $prefetch
         * @param null|array<mixed, mixed>|string $preconnect
         * @param null|array<mixed, mixed>|string $dnsPrefetch
         * @param null|array<string, string> $alternates
         * @param null|array<mixed, mixed>|string $feed
         * @param null|array<mixed, mixed>|string $icon
         * @param null|array<mixed, mixed>|string $favicon
         * @param null|array<mixed, mixed>|string $appleTouchIcon
         * @param null|array<mixed, mixed>|string $appleTouchStartupImage
         * @param null|array<mixed, mixed>|string $maskIcon
         * @param null|array<mixed, mixed>|string $manifest
         * @param null|array<mixed, mixed> $schema
         * @param null|array<mixed, mixed> $meta
         * @param null|array<mixed, mixed> $link
         * @param array<string, mixed> $extensions
         */
        $macro = function (
            string|array|null $title = null,
            ?string $description = null,
            string|array|true|null $canonical = null,
            string|RobotsRule|array|null $robots = null,
            ?string $themeColor = null,
            ?string $applicationName = null,
            ?string $colorScheme = null,
            ?string $referrer = null,
            ?string $viewport = null,
            ?string $appleWebAppTitle = null,
            ?bool $webAppCapable = null,
            ?string $appleWebAppStatusBarStyle = null,
            ?array $og = null,
            string|array|null $ogImage = null,
            string|array|null $ogVideo = null,
            string|array|null $ogAudio = null,
            ?array $twitter = null,
            string|array|null $twitterImage = null,
            string|array|null $preload = null,
            string|array|null $prefetch = null,
            string|array|null $preconnect = null,
            string|array|null $dnsPrefetch = null,
            ?array $alternates = null,
            string|array|null $feed = null,
            string|array|null $icon = null,
            string|array|null $favicon = null,
            string|array|null $appleTouchIcon = null,
            string|array|null $appleTouchStartupImage = null,
            string|array|null $maskIcon = null,
            string|array|null $manifest = null,
            ?array $schema = null,
            ?array $meta = null,
            ?array $link = null,
            array $extensions = [],
        ): PendingSingletonResourceRegistration {
            $arguments = get_defined_vars();

            $registration = HeadRouteMacros::bound($this, PendingSingletonResourceRegistration::class);

            return $registration->metadata(
                RouteAttributeParser::metadataFromArguments($arguments)
            );
        };

        return $macro;
    }
}
