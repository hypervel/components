<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class CursorPaginatorTest extends TestCase
{
    public function testReturnsRelevantContextInformation()
    {
        $p = new CursorPaginator($array = [['id' => 1], ['id' => 2], ['id' => 3]], 2, null, [
            'parameters' => ['id'],
        ]);

        $this->assertTrue($p->hasPages());
        $this->assertTrue($p->hasMorePages());
        $this->assertEquals([['id' => 1], ['id' => 2]], $p->items());

        $pageInfo = [
            'data' => [['id' => 1], ['id' => 2]],
            'path' => '/',
            'per_page' => 2,
            'next_cursor' => $this->getCursor(['id' => 2]),
            'next_page_url' => '/?cursor=' . $this->getCursor(['id' => 2]),
            'prev_cursor' => null,
            'prev_page_url' => null,
        ];

        $this->assertEquals($pageInfo, $p->toArray());
    }

    public function testPaginatorRemovesTrailingSlashes()
    {
        $p = new CursorPaginator(
            $array = [['id' => 4], ['id' => 5], ['id' => 6]],
            2,
            null,
            ['path' => 'http://website.com/test/', 'parameters' => ['id']]
        );

        $this->assertSame('http://website.com/test?cursor=' . $this->getCursor(['id' => 5]), $p->nextPageUrl());
    }

    public function testPaginatorGeneratesUrlsWithoutTrailingSlash()
    {
        $p = new CursorPaginator(
            $array = [['id' => 4], ['id' => 5], ['id' => 6]],
            2,
            null,
            ['path' => 'http://website.com/test', 'parameters' => ['id']]
        );

        $this->assertSame('http://website.com/test?cursor=' . $this->getCursor(['id' => 5]), $p->nextPageUrl());
    }

    public function testItRetrievesThePaginatorOptions()
    {
        $p = new CursorPaginator(
            $array = [['id' => 4], ['id' => 5], ['id' => 6]],
            2,
            null,
            $options = ['path' => 'http://website.com/test', 'parameters' => ['id']]
        );

        $this->assertSame($p->getOptions(), $options);
    }

    public function testPaginatorReturnsPath()
    {
        $p = new CursorPaginator(
            $array = [['id' => 4], ['id' => 5], ['id' => 6]],
            2,
            null,
            $options = ['path' => 'http://website.com/test', 'parameters' => ['id']]
        );

        $this->assertSame($p->path(), 'http://website.com/test');
    }

    public function testCanTransformPaginatorItems()
    {
        $p = new CursorPaginator(
            $array = [['id' => 4], ['id' => 5], ['id' => 6]],
            2,
            null,
            $options = ['path' => 'http://website.com/test', 'parameters' => ['id']]
        );

        $p->through(function ($item) {
            $item['id'] = $item['id'] + 2;

            return $item;
        });

        $this->assertInstanceOf(CursorPaginator::class, $p);
        $this->assertSame([['id' => 6], ['id' => 7]], $p->items());
    }

    public function testCursorPaginatorOnFirstAndLastPage()
    {
        $paginator = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], 2, null, [
            'parameters' => ['id'],
        ]);

        $this->assertTrue($paginator->onFirstPage());
        $this->assertFalse($paginator->onLastPage());

        $cursor = new Cursor(['id' => 3]);
        $paginator = new CursorPaginator([['id' => 3], ['id' => 4]], 2, $cursor, [
            'parameters' => ['id'],
        ]);

        $this->assertFalse($paginator->onFirstPage());
        $this->assertTrue($paginator->onLastPage());
    }

    public function testReturnEmptyCursorWhenItemsAreEmpty()
    {
        $cursor = new Cursor(['id' => 25], true);

        $p = new CursorPaginator(new Collection(), 25, $cursor, [
            'path' => 'http://website.com/test',
            'cursorName' => 'cursor',
            'parameters' => ['id'],
        ]);

        $this->assertInstanceOf(CursorPaginator::class, $p);

        $this->assertSame([
            'data' => [],
            'path' => 'http://website.com/test',
            'per_page' => 25,
            'next_cursor' => null,
            'next_page_url' => null,
            'prev_cursor' => null,
            'prev_page_url' => null,
        ], $p->toArray());
    }

    public function testCursorPaginatorToJson()
    {
        $paginator = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], 2, null);
        $results = $paginator->toJson();
        $expected = json_encode($paginator->toArray());

        $this->assertJsonStringEqualsJsonString($expected, $results);
        $this->assertSame($expected, $results);
    }

    public function testCursorPaginatorToPrettyJson()
    {
        $paginator = new CursorPaginator([['id' => '1'], ['id' => '2'], ['id' => '3'], ['id' => '4']], 2, null);
        $results = $paginator->toPrettyJson();
        $expected = $paginator->toJson(JSON_PRETTY_PRINT);

        $this->assertJsonStringEqualsJsonString($expected, $results);
        $this->assertSame($expected, $results);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);

        $results = $paginator->toPrettyJson(JSON_NUMERIC_CHECK);
        $this->assertStringContainsString("\n", $results);
        $this->assertStringContainsString('    ', $results);
        $this->assertStringContainsString('"id": 1', $results);
    }

    public function testNextCursorReturnsCursorObject()
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 2, null, [
            'parameters' => ['id'],
        ]);

        $nextCursor = $p->nextCursor();
        $this->assertInstanceOf(Cursor::class, $nextCursor);
        $this->assertTrue($nextCursor->pointsToNextItems());
        $this->assertSame(2, $nextCursor->parameter('id'));
    }

    public function testPreviousCursorReturnsCursorObject()
    {
        $cursor = new Cursor(['id' => 3], true);
        $p = new CursorPaginator([['id' => 3], ['id' => 4], ['id' => 5]], 2, $cursor, [
            'parameters' => ['id'],
        ]);

        $previousCursor = $p->previousCursor();
        $this->assertInstanceOf(Cursor::class, $previousCursor);
        $this->assertTrue($previousCursor->pointsToPreviousItems());
        $this->assertSame(3, $previousCursor->parameter('id'));
    }

    public function testPreviousCursorReturnsNullWhenNoCursor()
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2]], 2, null, [
            'parameters' => ['id'],
        ]);

        $this->assertNull($p->previousCursor());
    }

    public function testNextCursorReturnsNullOnLastPage()
    {
        $cursor = new Cursor(['id' => 3]);
        $p = new CursorPaginator([['id' => 3], ['id' => 4]], 2, $cursor, [
            'parameters' => ['id'],
        ]);

        $this->assertNull($p->nextCursor());
    }

    public function testGetCursorForItem()
    {
        $p = new CursorPaginator([['id' => 1]], 1, null, [
            'parameters' => ['id'],
        ]);

        $cursor = $p->getCursorForItem(['id' => 42], true);
        $this->assertInstanceOf(Cursor::class, $cursor);
        $this->assertSame(42, $cursor->parameter('id'));
        $this->assertTrue($cursor->pointsToNextItems());

        $cursor = $p->getCursorForItem(['id' => 42], false);
        $this->assertTrue($cursor->pointsToPreviousItems());
    }

    public function testGetParametersForItem()
    {
        $p = new CursorPaginator([['id' => 1, 'name' => 'a']], 1, null, [
            'parameters' => ['id', 'name'],
        ]);

        $params = $p->getParametersForItem(['id' => 5, 'name' => 'test']);
        $this->assertSame(['id' => 5, 'name' => 'test'], $params);
    }

    public function testFragmentAppearsInUrl()
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 2, null, [
            'parameters' => ['id'],
        ]);
        $p->fragment('section');

        $this->assertSame('section', $p->fragment());

        $nextUrl = $p->nextPageUrl();
        $this->assertStringContainsString('#section', $nextUrl);
    }

    public function testAppendsQueryParams()
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 2, null, [
            'parameters' => ['id'],
        ]);
        $p->appends('sort', 'name');

        $nextUrl = $p->nextPageUrl();
        $this->assertStringContainsString('sort=name', $nextUrl);
    }

    public function testCursorReturnsCurrentCursor()
    {
        $cursor = new Cursor(['id' => 10], true);
        $p = new CursorPaginator([['id' => 10]], 1, $cursor, [
            'parameters' => ['id'],
        ]);

        $this->assertSame($cursor, $p->cursor());
    }

    public function testCursorReturnsNullWhenNoCursor()
    {
        $p = new CursorPaginator([['id' => 1]], 1, null);

        $this->assertNull($p->cursor());
    }

    public function testGetCursorNameAndSetCursorName()
    {
        $p = new CursorPaginator([['id' => 1]], 1, null);

        $this->assertSame('cursor', $p->getCursorName());

        $result = $p->setCursorName('page_cursor');
        $this->assertSame($p, $result);
        $this->assertSame('page_cursor', $p->getCursorName());
    }

    public function testIsEmptyAndIsNotEmpty()
    {
        $p = new CursorPaginator([], 2, null);
        $this->assertTrue($p->isEmpty());
        $this->assertFalse($p->isNotEmpty());

        $p = new CursorPaginator([['id' => 1]], 2, null);
        $this->assertFalse($p->isEmpty());
        $this->assertTrue($p->isNotEmpty());
    }

    public function testCount()
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 3, null);

        $this->assertSame(3, $p->count());
    }

    public function testArrayAccess()
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 3, null);

        // offsetExists
        $this->assertTrue(isset($p[0]));
        $this->assertFalse(isset($p[5]));

        // offsetGet
        $this->assertSame(['id' => 1], $p[0]);

        // offsetSet
        $p[1] = ['id' => 99];
        $this->assertSame(['id' => 99], $p[1]);

        // offsetUnset
        unset($p[0]);
        $this->assertFalse(isset($p[0]));
    }

    protected function getCursor($params, $isNext = true)
    {
        return (new Cursor($params, $isNext))->encode();
    }
}
