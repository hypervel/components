<?php

declare(strict_types=1);

namespace Hypervel\Tests\Head\Feature\Tags;

use Hypervel\Head\Enums\RobotsRule;
use Hypervel\Head\Facades\Head;
use Hypervel\Support\Facades\Route;
use Hypervel\Tests\Head\TestCase;

class RobotsTest extends TestCase
{
    public function testDoesNotRenderRobotsTagsByDefault(): void
    {
        $this->assertStringNotContainsString('name="robots"', Head::toHtml());
    }

    public function testRendersRobotsTagsFromStrings(): void
    {
        Head::robots('noindex, nofollow');

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', Head::toHtml());
    }

    public function testRendersRobotsTagsFromRules(): void
    {
        Head::robots([RobotsRule::NoIndex, RobotsRule::NoFollow]);

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', Head::toHtml());
    }

    public function testRendersRobotsTagsFromASingleRule(): void
    {
        Head::robots(RobotsRule::NoIndex);

        $this->assertStringContainsString('<meta name="robots" content="noindex">', Head::toHtml());
    }

    public function testRendersTagsForPagesSearchableByRobots(): void
    {
        Head::searchableByRobots();

        $this->assertStringContainsString('<meta name="robots" content="all">', Head::toHtml());
    }

    public function testRendersTagsForPagesHiddenFromRobots(): void
    {
        Head::hiddenFromRobots();

        $this->assertStringContainsString('<meta name="robots" content="none">', Head::toHtml());
    }

    public function testRendersRobotsTagsFromMixedStringAndEnumRules(): void
    {
        Head::robots(['noindex', RobotsRule::NoFollow]);

        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', Head::toHtml());
    }

    public function testResolvesRobotsRulesFromRouteAttributes(): void
    {
        Route::get('/private', fn (): string => Head::toHtml())
            ->withHead(robots: [RobotsRule::NoIndex, RobotsRule::NoFollow]);

        $this->get('/private')
            ->assertOk()
            ->assertSee('<meta name="robots" content="noindex, nofollow">', false);
    }
}
