<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Routing;

use Error;
use Hypervel\Head\Facades\Head;
use Hypervel\Head\Routing\RouteAttributeParser;
use Hypervel\Routing\Route as RoutingRoute;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;

class WithHeadTest extends TestCase
{
    public function testDefinesHeadMetadataFluentlyOnRoutes(): void
    {
        $route = Route::view('/contact', 'contact')
            ->name('contact')
            ->withHead(
                title: 'Contact Us',
                description: 'Get in touch.',
            );

        $this->assertInstanceOf(RoutingRoute::class, $route);
        $this->assertSame('contact', $route->getName());
        $this->assertSame([[
            'title' => 'Contact Us',
            'description' => 'Get in touch.',
        ]], array_values($route->getMetadata(RouteAttributeParser::HEAD)));
    }

    public function testDefinesHeadMetadataAtTheLeadingGroupPosition(): void
    {
        Route::withHead(description: 'Admin description.', robots: 'noindex, nofollow')
            ->prefix('admin')
            ->group(function (): void {
                Route::get('/dashboard', fn (): string => Head::toHtml())
                    ->withHead(title: 'Dashboard');
            });

        $this->get('/admin/dashboard')
            ->assertOk()
            ->assertSee('<title>Dashboard</title>', false)
            ->assertSee('<meta name="description" content="Admin description.">', false)
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }

    public function testDefinesHeadMetadataAfterOtherGroupAttributes(): void
    {
        Route::prefix('admin')
            ->withHead(og: ['siteName' => 'Acme'])
            ->name('admin.')
            ->group(function (): void {
                Route::get('/reports', fn (): string => Head::toHtml())
                    ->name('reports')
                    ->withHead(title: 'Reports', og: ['title' => 'Reports']);
            });

        $this->get('/admin/reports')
            ->assertOk()
            ->assertSee('<title>Reports</title>', false)
            ->assertSee('<meta property="og:site_name" content="Acme">', false)
            ->assertSee('<meta property="og:title" content="Reports">', false);
    }

    public function testDefinesHeadMetadataOnResourceAndSingletonRoutes(): void
    {
        Route::resource('posts', 'PostController')->withHead(
            robots: 'index, follow',
        );

        Route::singleton('profile', 'ProfileController')->withHead(
            title: 'Your Profile',
        );

        $posts = Route::getRoutes()->getByName('posts.index');
        $profile = Route::getRoutes()->getByName('profile.show');

        $this->assertSame([[
            'robots' => 'index, follow',
        ]], array_values($posts?->getMetadata(RouteAttributeParser::HEAD)));
        $this->assertSame([[
            'title' => 'Your Profile',
        ]], array_values($profile?->getMetadata(RouteAttributeParser::HEAD)));
    }

    public function testStoresCacheFriendlyMetadataIncludingExtensionAttributes(): void
    {
        $route = Route::get('/article', fn (): string => 'Article')->withHead(
            title: 'Article',
            extensions: ['readingTime' => 4],
        );

        $this->assertSame([[
            'title' => 'Article',
            'readingTime' => 4,
        ]], array_values($route->getMetadata(RouteAttributeParser::HEAD)));

        $route->prepareForSerialization();
        $restored = unserialize(serialize($route));
        $this->assertSame($route->getMetadata(RouteAttributeParser::HEAD), $restored->getMetadata(RouteAttributeParser::HEAD));
    }

    public function testRejectsUnknownNamedWithHeadArguments(): void
    {
        $arguments = ['heading' => 'Dashboard'];

        $this->expectException(Error::class);
        $this->expectExceptionMessage('Unknown named parameter $heading');
        Route::get('/unknown', fn (): string => 'Unknown')->withHead(...$arguments);
    }
}
