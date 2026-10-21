<?php

declare(strict_types=1);

namespace Hypervel\Head\PHPStan;

use Hypervel\Head\Enums\RobotsRule;

/**
 * Declare the routing macro parameters for analysis without running provider boot.
 */
interface RoutingMacros
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
    ): object;
}
