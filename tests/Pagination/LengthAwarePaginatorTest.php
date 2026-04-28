<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Testbench\TestCase;

class LengthAwarePaginatorTest extends TestCase
{
    private LengthAwarePaginator $p;

    private array $options;

    protected function setUp(): void
    {
        parent::setUp();

        $this->options = ['onEachSide' => 5];
        $this->p = new LengthAwarePaginator($array = ['item1', 'item2', 'item3', 'item4'], 4, 2, 2, $this->options);
    }

    protected function tearDown(): void
    {
        unset($this->p);

        parent::tearDown();
    }

    public function testLengthAwarePaginatorGetAndSetPageName()
    {
        $this->assertSame('page', $this->p->getPageName());

        $this->p->setPageName('p');
        $this->assertSame('p', $this->p->getPageName());
    }

    public function testLengthAwarePaginatorCanGiveMeRelevantPageInformation()
    {
        $this->assertEquals(2, $this->p->lastPage());
        $this->assertEquals(2, $this->p->currentPage());
        $this->assertTrue($this->p->hasPages());
        $this->assertFalse($this->p->hasMorePages());
        $this->assertEquals(['item1', 'item2', 'item3', 'item4'], $this->p->items());
    }

    public function testLengthAwarePaginatorSetCorrectInformationWithNoItems()
    {
        $paginator = new LengthAwarePaginator([], 0, 2, 1);

        $this->assertEquals(1, $paginator->lastPage());
        $this->assertEquals(1, $paginator->currentPage());
        $this->assertFalse($paginator->hasPages());
        $this->assertFalse($paginator->hasMorePages());
        $this->assertEmpty($paginator->items());
    }

    public function testLengthAwarePaginatorOnFirstAndLastPage()
    {
        $paginator = new LengthAwarePaginator(['1', '2', '3', '4'], 4, 2, 2);

        $this->assertTrue($paginator->onLastPage());
        $this->assertFalse($paginator->onFirstPage());

        $paginator = new LengthAwarePaginator(['1', '2', '3', '4'], 4, 2, 1);

        $this->assertFalse($paginator->onLastPage());
        $this->assertTrue($paginator->onFirstPage());
    }

    public function testLengthAwarePaginatorCanGenerateUrls()
    {
        $this->p->setPath('http://website.com');
        $this->p->setPageName('foo');

        $this->assertSame(
            'http://website.com',
            $this->p->path()
        );

        $this->assertSame(
            'http://website.com?foo=2',
            $this->p->url($this->p->currentPage())
        );

        $this->assertSame(
            'http://website.com?foo=1',
            $this->p->url($this->p->currentPage() - 1)
        );

        $this->assertSame(
            'http://website.com?foo=1',
            $this->p->url($this->p->currentPage() - 2)
        );
    }

    public function testLengthAwarePaginatorCanGenerateUrlsWithQuery()
    {
        $this->p->setPath('http://website.com?sort_by=date');
        $this->p->setPageName('foo');

        $this->assertSame(
            'http://website.com?sort_by=date&foo=2',
            $this->p->url($this->p->currentPage())
        );
    }

    public function testLengthAwarePaginatorCanGenerateUrlsWithoutTrailingSlashes()
    {
        $this->p->setPath('http://website.com/test');
        $this->p->setPageName('foo');

        $this->assertSame(
            'http://website.com/test?foo=2',
            $this->p->url($this->p->currentPage())
        );

        $this->assertSame(
            'http://website.com/test?foo=1',
            $this->p->url($this->p->currentPage() - 1)
        );

        $this->assertSame(
            'http://website.com/test?foo=1',
            $this->p->url($this->p->currentPage() - 2)
        );
    }

    public function testLengthAwarePaginatorCorrectlyGenerateUrlsWithQueryAndSpaces()
    {
        $this->p->setPath('http://website.com?key=value%20with%20spaces');
        $this->p->setPageName('foo');

        $this->assertSame(
            'http://website.com?key=value%20with%20spaces&foo=2',
            $this->p->url($this->p->currentPage())
        );
    }

    public function testItRetrievesThePaginatorOptions()
    {
        $this->assertSame($this->options, $this->p->getOptions());
    }

    public function testNextPageUrl()
    {
        $paginator = new LengthAwarePaginator([1, 2], 10, 2);

        $this->assertSame('/?page=2', $paginator->nextPageUrl());

        $paginator = new LengthAwarePaginator([1, 2], 10, 2, 5);

        $this->assertSame(null, $paginator->nextPageUrl());
    }

    public function testFirstItem()
    {
        $paginator = new LengthAwarePaginator([1, 2], 10, 2, 2);

        $this->assertSame(3, $paginator->firstItem());
        $this->assertSame(4, $paginator->lastItem());
    }

    public function testAppends()
    {
        $paginator = new LengthAwarePaginator([1, 2], 10, 2, 2);
        $paginator = $paginator->appends('keyword', 'Hypervel');
        $this->assertSame('/?keyword=Hypervel&page=1', $paginator->url(1));

        $paginator = new LengthAwarePaginator([1, 2], 10, 2, 2);
        $paginator = $paginator->appends('frameworks', []);
        $this->assertSame('/?page=1', $paginator->url(1));

        $paginator = new LengthAwarePaginator([1, 2], 10, 2, 2);
        $paginator = $paginator->appends('frameworks', ['Hypervel', 'Laravel']);
        $this->assertSame('/?frameworks%5B0%5D=Hypervel&frameworks%5B1%5D=Laravel&page=1', $paginator->url(1));

        $paginator = new LengthAwarePaginator([1, 2], 10, 2, 2);
        $paginator = $paginator->appends('settings', ['id' => '1', 'name' => 'Hypervel']);
        $this->assertSame('/?settings%5Bid%5D=1&settings%5Bname%5D=Hypervel&page=1', $paginator->url(1));
    }

    public function testToJson()
    {
        $paginator = new LengthAwarePaginator([1, 2], 10, 2, 2);

        $this->assertSame(json_encode($paginator->toArray()), $paginator->toJson());

        $paginator = new Paginator([1, 2], 2, 2);

        $this->assertSame(json_encode($paginator->toArray()), $paginator->toJson());
    }

    public function testTotal()
    {
        $paginator = new LengthAwarePaginator([1, 2], 50, 2, 1);

        $this->assertSame(50, $paginator->total());
    }

    public function testLinkCollection()
    {
        $paginator = new LengthAwarePaginator([1, 2], 4, 2, 1);

        $links = $paginator->linkCollection();

        $this->assertInstanceOf(\Hypervel\Support\Collection::class, $links);
        $this->assertGreaterThanOrEqual(3, $links->count()); // prev + pages + next

        // First link is "Previous"
        $first = $links->first();
        $this->assertNull($first['url']); // on first page, no previous
        $this->assertFalse($first['active']);

        // Last link is "Next"
        $last = $links->last();
        $this->assertSame('/?page=2', $last['url']);
        $this->assertFalse($last['active']);
    }

    public function testToPrettyJson()
    {
        $paginator = new LengthAwarePaginator(['item/1', 'item/2'], 2, 2, 1);
        $results = $paginator->toPrettyJson();
        $expected = $paginator->toJson(JSON_PRETTY_PRINT);

        $this->assertJsonStringEqualsJsonString($expected, $results);
        $this->assertSame($expected, $results);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);
    }

    public function testPreviousPageUrlReturnsNullOnFirstPage()
    {
        $paginator = new LengthAwarePaginator([1, 2], 10, 2, 1);

        $this->assertNull($paginator->previousPageUrl());
    }

    public function testFirstItemAndLastItemReturnNullWhenEmpty()
    {
        $paginator = new LengthAwarePaginator([], 0, 2, 1);

        $this->assertNull($paginator->firstItem());
        $this->assertNull($paginator->lastItem());
    }
}
