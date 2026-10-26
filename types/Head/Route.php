<?php

declare(strict_types=1);

namespace Hypervel\Types\Head;

use Hypervel\Head\Enums\RobotsRule;
use Hypervel\Routing\PendingResourceRegistration;
use Hypervel\Routing\PendingSingletonResourceRegistration;
use Hypervel\Routing\Route;
use Hypervel\Routing\Router;
use Hypervel\Routing\RouteRegistrar;
use Hypervel\Support\Facades\Route as RouteFacade;

use function PHPStan\Testing\assertType;

function routingMacros(
    Router $router,
    RouteRegistrar $registrar,
    Route $route,
    PendingResourceRegistration $resource,
    PendingSingletonResourceRegistration $singleton,
): void {
    assertType(RouteRegistrar::class, $router->withHead(title: 'Page'));
    assertType(RouteRegistrar::class, $registrar->withHead(description: 'Description'));
    assertType(Route::class, $route->withHead(canonical: true));
    assertType(PendingResourceRegistration::class, $resource->withHead(robots: RobotsRule::NoIndex));
    assertType(PendingSingletonResourceRegistration::class, $singleton->withHead(robots: [RobotsRule::NoIndex]));
    assertType(RouteRegistrar::class, RouteFacade::withHead(title: 'Group')->prefix('admin'));
    assertType(Route::class, RouteFacade::prefix('admin')->withHead(title: 'Group')->get('/'));
    assertType(Route::class, RouteFacade::get('/')->withHead(title: 'Page')->middleware('auth'));
    assertType(RouteRegistrar::class, $router->middleware('auth'));
    assertType(PendingResourceRegistration::class, RouteFacade::resource('posts', 'PostController')->withHead(title: 'Posts'));
    assertType(PendingSingletonResourceRegistration::class, RouteFacade::singleton('profile', 'ProfileController')->withHead(title: 'Profile'));

    $route->withHead(
        title: ['value' => 'Page', 'suffix' => ' - Site'],
        description: 'Description',
        canonical: ['value' => '/page', 'forceHttps' => true],
        robots: ['index', RobotsRule::Follow],
        themeColor: '#000000',
        applicationName: 'Site',
        colorScheme: 'light dark',
        referrer: 'same-origin',
        viewport: 'width=device-width',
        appleWebAppTitle: 'Site',
        webAppCapable: true,
        appleWebAppStatusBarStyle: 'default',
        og: ['type' => 'website'],
        ogImage: '/image.png',
        ogVideo: ['url' => '/video.mp4'],
        ogAudio: '/audio.mp3',
        twitter: ['site' => '@site'],
        twitterImage: '/image.png',
        preload: ['href' => '/style.css', 'as' => 'style'],
        prefetch: '/next',
        preconnect: 'https://assets.test',
        dnsPrefetch: ['https://assets.test'],
        alternates: ['en' => '/en'],
        feed: ['href' => '/feed', 'title' => 'Feed'],
        icon: '/icon.png',
        favicon: '/favicon.ico',
        appleTouchIcon: '/apple.png',
        appleTouchStartupImage: '/startup.png',
        maskIcon: ['href' => '/mask.svg', 'color' => '#000000'],
        manifest: '/site.webmanifest',
        schema: ['@type' => 'WebPage'],
        meta: ['author' => 'Author'],
        link: [['rel' => 'help', 'href' => '/help']],
        extensions: ['readingTime' => 5],
    );

    $route->withHead(heading: 'Page'); // @phpstan-ignore argument.unknown
    $route->withHead(title: 123); // @phpstan-ignore argument.type
}

class CustomRoute extends Route
{
}

class CustomRouter extends Router
{
}

function routingSubclassMacros(CustomRoute $route, CustomRouter $router): void
{
    assertType(CustomRoute::class, $route->withHead(title: 'Page'));
    assertType(RouteRegistrar::class, $router->withHead(title: 'Group'));
}
