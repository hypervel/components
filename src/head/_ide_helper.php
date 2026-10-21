<?php

declare(strict_types=1);

/**
 * Editor helper declarations for the withHead() route macros registered by
 * hypervel/head. This file is never autoloaded or executed; editors such as
 * VS Code with Intelephense index it so withHead() autocompletes on Hypervel's
 * routing classes. Keep the signatures in sync with the macros registered
 * by \Hypervel\Head\Routing\HeadRouteMacros and its PHPStan signature.
 *
 * @see \Hypervel\Head\Routing\HeadRouteMacros
 */

namespace Hypervel\Routing {
    use Hypervel\Head\Enums\RobotsRule;

    class Router
    {
        /**
         * Define head metadata for the route, stored through Hypervel's native
         * route metadata API. Provided by hypervel/head.
         *
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
        public function withHead(
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
        }
    }
}

namespace Hypervel\Routing {
    use Hypervel\Head\Enums\RobotsRule;

    class RouteRegistrar
    {
        /**
         * Define head metadata for the route, stored through Hypervel's native
         * route metadata API. Provided by hypervel/head.
         *
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
        public function withHead(
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
        }
    }
}

namespace Hypervel\Routing {
    use Hypervel\Head\Enums\RobotsRule;

    class Route
    {
        /**
         * Define head metadata for the route, stored through Hypervel's native
         * route metadata API. Provided by hypervel/head.
         *
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
        public function withHead(
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
        }
    }
}

namespace Hypervel\Routing {
    use Hypervel\Head\Enums\RobotsRule;

    class PendingResourceRegistration
    {
        /**
         * Define head metadata for the route, stored through Hypervel's native
         * route metadata API. Provided by hypervel/head.
         *
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
        public function withHead(
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
        }
    }
}

namespace Hypervel\Routing {
    use Hypervel\Head\Enums\RobotsRule;

    class PendingSingletonResourceRegistration
    {
        /**
         * Define head metadata for the route, stored through Hypervel's native
         * route metadata API. Provided by hypervel/head.
         *
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
        public function withHead(
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
        }
    }
}

namespace Hypervel\Support\Facades {
    use Hypervel\Head\Enums\RobotsRule;
    use Hypervel\Routing\RouteRegistrar;

    class Route
    {
        /**
         * Define head metadata for the route, stored through Hypervel's native
         * route metadata API. Provided by hypervel/head.
         *
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
        public static function withHead(
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
        }
    }
}
