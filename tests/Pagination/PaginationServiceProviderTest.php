<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Contracts\View\Factory as ViewFactoryContract;
use Hypervel\Pagination\AbstractPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;

/**
 * Tests that PaginationServiceProvider registers the pagination view namespace
 * so the shipped Tailwind views can be rendered without app developers having
 * to publish them first.
 */
class PaginationServiceProviderTest extends TestCase
{
    public function testPaginationTailwindViewResolves(): void
    {
        $this->assertTrue($this->app->make(ViewFactoryContract::class)->exists('pagination::tailwind'));
    }

    public function testPaginationSimpleTailwindViewResolves(): void
    {
        $this->assertTrue($this->app->make(ViewFactoryContract::class)->exists('pagination::simple-tailwind'));
    }

    public function testLengthAwarePaginatorRendersTailwindView(): void
    {
        $paginator = new LengthAwarePaginator(new Collection(['a', 'b', 'c']), 30, 10);

        $output = (string) $paginator->render();

        $this->assertNotEmpty($output);
    }

    public function testSimplePaginatorRendersSimpleTailwindView(): void
    {
        $paginator = (new Paginator(new Collection(['a', 'b', 'c']), 2))->hasMorePagesWhen(true);

        $output = (string) $paginator->render();

        $this->assertNotEmpty($output);
    }

    public function testDefaultViewSetterApplies(): void
    {
        AbstractPaginator::defaultView('custom::pagination');

        $this->assertSame('custom::pagination', AbstractPaginator::$defaultView);
    }

    public function testDefaultSimpleViewSetterApplies(): void
    {
        AbstractPaginator::defaultSimpleView('custom::simple');

        $this->assertSame('custom::simple', AbstractPaginator::$defaultSimpleView);
    }

    public function testUseTailwindResetsBothDefaults(): void
    {
        AbstractPaginator::defaultView('other::view');
        AbstractPaginator::defaultSimpleView('other::simple');

        AbstractPaginator::useTailwind();

        $this->assertSame('pagination::tailwind', AbstractPaginator::$defaultView);
        $this->assertSame('pagination::simple-tailwind', AbstractPaginator::$defaultSimpleView);
    }
}
