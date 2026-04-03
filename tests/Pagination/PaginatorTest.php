<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Pagination\Paginator;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class PaginatorTest extends TestCase
{
    public function testSimplePaginatorReturnsRelevantContextInformation()
    {
        /** @var Paginator<int, string> $p */
        $p = new Paginator(['item3', 'item4', 'item5'], 2, 2);

        $this->assertEquals(2, $p->currentPage());
        $this->assertTrue($p->hasPages());
        $this->assertTrue($p->hasMorePages());
        $this->assertEquals(['item3', 'item4'], $p->items());

        $pageInfo = [
            'per_page' => 2,
            'current_page' => 2,
            'first_page_url' => '/?page=1',
            'current_page_url' => '/?page=2',
            'next_page_url' => '/?page=3',
            'prev_page_url' => '/?page=1',
            'from' => 3,
            'to' => 4,
            'data' => ['item3', 'item4'],
            'path' => '/',
        ];

        $this->assertEquals($pageInfo, $p->toArray());
    }

    public function testPaginatorRemovesTrailingSlashes()
    {
        $p = new Paginator(['item1', 'item2', 'item3'], 2, 2, ['path' => 'http://website.com/test/']);

        $this->assertSame('http://website.com/test?page=1', $p->previousPageUrl());
    }

    public function testPaginatorGeneratesUrlsWithoutTrailingSlash()
    {
        $p = new Paginator(['item1', 'item2', 'item3'], 2, 2, ['path' => 'http://website.com/test']);

        $this->assertSame('http://website.com/test?page=1', $p->previousPageUrl());
    }

    public function testItRetrievesThePaginatorOptions()
    {
        $p = new Paginator(['item1', 'item2', 'item3'], 2, 2, ['path' => 'http://website.com/test']);

        $this->assertSame(['path' => 'http://website.com/test'], $p->getOptions());
    }

    public function testPaginatorReturnsPath()
    {
        $p = new Paginator(['item1', 'item2', 'item3'], 2, 2, ['path' => 'http://website.com/test']);

        $this->assertSame('http://website.com/test', $p->path());
    }

    public function testCanTransformPaginatorItems()
    {
        $p = new Paginator(['item1', 'item2', 'item3'], 3, 1, ['path' => 'http://website.com/test']);

        $p->through(function ($item) {
            return substr($item, 4, 1);
        });

        $this->assertInstanceOf(Paginator::class, $p);
        $this->assertSame(['1', '2', '3'], $p->items());
    }

    public function testPaginatorToJson()
    {
        $p = new Paginator(['item1', 'item2', 'item3'], 3, 1);
        $results = $p->toJson();
        $expected = json_encode($p->toArray());

        $this->assertJsonStringEqualsJsonString($expected, $results);
        $this->assertSame($expected, $results);
    }

    public function testPaginatorToPrettyJson()
    {
        $p = new Paginator(['item/1', 'item/2', 'item/3'], 3, 1);
        $results = $p->toPrettyJson();
        $expected = $p->toJson(JSON_PRETTY_PRINT);

        $this->assertJsonStringEqualsJsonString($expected, $results);
        $this->assertSame($expected, $results);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);
        $this->assertStringContainsString('item\/1', $results);

        $results = $p->toPrettyJson(JSON_UNESCAPED_SLASHES);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);
        $this->assertStringContainsString('item/1', $results);
    }

    public function testPreviousPageUrlReturnsNullOnFirstPage()
    {
        $p = new Paginator(['item1', 'item2'], 2, 1);

        $this->assertNull($p->previousPageUrl());
    }

    public function testFragmentGetAndSet()
    {
        $p = new Paginator(['item1', 'item2'], 2, 1);

        $this->assertNull($p->fragment());

        $result = $p->fragment('section');
        $this->assertSame($p, $result);
        $this->assertSame('section', $p->fragment());
    }

    public function testFragmentAppearsInUrl()
    {
        $p = new Paginator(['item1', 'item2', 'item3'], 2, 1);
        $p->fragment('top');

        $this->assertSame('/?page=2#top', $p->url(2));
    }

    public function testIsEmptyAndIsNotEmpty()
    {
        $p = new Paginator([], 2, 1);
        $this->assertTrue($p->isEmpty());
        $this->assertFalse($p->isNotEmpty());

        $p = new Paginator(['item1'], 2, 1);
        $this->assertFalse($p->isEmpty());
        $this->assertTrue($p->isNotEmpty());
    }

    public function testCount()
    {
        $p = new Paginator(['item1', 'item2', 'item3'], 3, 1);

        $this->assertSame(3, $p->count());
    }

    public function testGetCollectionAndSetCollection()
    {
        $p = new Paginator(['item1', 'item2'], 2, 1);

        $collection = $p->getCollection();
        $this->assertInstanceOf(Collection::class, $collection);
        $this->assertSame(['item1', 'item2'], $collection->all());

        $newCollection = new Collection(['a', 'b', 'c']);
        $result = $p->setCollection($newCollection);
        $this->assertSame($p, $result);
        $this->assertSame(['a', 'b', 'c'], $p->items());
    }

    public function testArrayAccess()
    {
        $p = new Paginator(['item1', 'item2', 'item3'], 3, 1);

        // offsetExists
        $this->assertTrue(isset($p[0]));
        $this->assertTrue(isset($p[2]));
        $this->assertFalse(isset($p[5]));

        // offsetGet
        $this->assertSame('item1', $p[0]);
        $this->assertSame('item3', $p[2]);

        // offsetSet
        $p[1] = 'replaced';
        $this->assertSame('replaced', $p[1]);

        // offsetUnset
        unset($p[0]);
        $this->assertFalse(isset($p[0]));
    }

    public function testWithPathIsFluent()
    {
        $p = new Paginator(['item1'], 1, 1);

        $result = $p->withPath('http://example.com/items');
        $this->assertSame($p, $result);
        $this->assertSame('http://example.com/items', $p->path());
    }

    public function testGetUrlRange()
    {
        $p = new Paginator(['item1', 'item2'], 2, 1);

        $range = $p->getUrlRange(1, 3);
        $this->assertSame([
            1 => '/?page=1',
            2 => '/?page=2',
            3 => '/?page=3',
        ], $range);
    }

    public function testHasMorePagesWhen()
    {
        $p = new Paginator(['item1', 'item2'], 2, 1);

        $this->assertFalse($p->hasMorePages());

        $p->hasMorePagesWhen(true);
        $this->assertTrue($p->hasMorePages());

        $p->hasMorePagesWhen(false);
        $this->assertFalse($p->hasMorePages());
    }

    public function testEscapeWhenCastingToString()
    {
        $p = new Paginator(['item1'], 1, 1);

        $result = $p->escapeWhenCastingToString();
        $this->assertSame($p, $result);
    }

    public function testWithQueryString()
    {
        Paginator::queryStringResolver(fn () => ['sort' => 'name', 'direction' => 'asc']);

        $p = new Paginator(['item1', 'item2', 'item3'], 2, 1);
        $p->withQueryString();

        $this->assertSame('/?sort=name&direction=asc&page=2', $p->url(2));
    }
}
