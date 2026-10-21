<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature;

use Hypervel\Head\Facades\Head;
use Hypervel\Head\HeadManager;
use Hypervel\Head\TagRegistry;
use Hypervel\Head\Tags\Title;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\Fixtures\ConflictingRouteAttribute;
use Hypervel\Tests\Head\Fixtures\ReadingTime;
use Hypervel\Tests\Head\TestCase;
use InvalidArgumentException;
use stdClass;

class HeadDataTest extends TestCase
{
    public function testRendersARegisteredCustomTagBuilder(): void
    {
        Head::extend(ReadingTime::class);

        Head::macro('readingTime', function (int $minutes): HeadManager {
            $this->headData()->overlayBuilder(ReadingTime::make($minutes));

            return $this;
        });

        Head::readingTime(7);

        $html = Head::toHtml();
        $this->assertStringContainsString('<meta name="twitter:label1" content="Reading time">', $html);
        $this->assertStringContainsString('<meta name="twitter:data1" content="7 min read">', $html);

        $this->assertSame(7, Head::toArray()['readingTime']);
    }

    public function testResolvesCustomBuilderValuesFromRouteAttributes(): void
    {
        $this->app->make(TagRegistry::class)->routeAttributeKeys();
        Head::extend(ReadingTime::class);

        Route::get('/article', fn (): string => Head::toHtml())
            ->withHead(extensions: ['readingTime' => 4]);

        $this->get('/article')
            ->assertOk()
            ->assertSee('<meta name="twitter:data1" content="4 min read">', false);
    }

    public function testRejectsTagExtensionsThatDoNotExtendTagBuilders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Head tag extensions must extend');
        Head::extend(stdClass::class);
    }

    public function testRejectsBuiltInTagBuilders(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Built-in head tag builders are already registered.');
        Head::extend(Title::class);
    }

    public function testRejectsTagExtensionsThatRegisterDuplicateRouteAttributeKeys(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Head tag extensions may not register route attributes already registered by another builder: title.');
        Head::extend(ConflictingRouteAttribute::class);
    }
}
