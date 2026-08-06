<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use ArrayObject;
use Exception;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Pagination\Cursor;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Support\Collection;
use Hypervel\Tests\TestCase;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;

class CursorPaginatorTest extends TestCase
{
    public function testReturnsRelevantContextInformation(): void
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

    public function testPaginatorRemovesTrailingSlashes(): void
    {
        $p = new CursorPaginator(
            $array = [['id' => 4], ['id' => 5], ['id' => 6]],
            2,
            null,
            ['path' => 'http://website.com/test/', 'parameters' => ['id']]
        );

        $this->assertSame('http://website.com/test?cursor=' . $this->getCursor(['id' => 5]), $p->nextPageUrl());
    }

    public function testPaginatorGeneratesUrlsWithoutTrailingSlash(): void
    {
        $p = new CursorPaginator(
            $array = [['id' => 4], ['id' => 5], ['id' => 6]],
            2,
            null,
            ['path' => 'http://website.com/test', 'parameters' => ['id']]
        );

        $this->assertSame('http://website.com/test?cursor=' . $this->getCursor(['id' => 5]), $p->nextPageUrl());
    }

    public function testItRetrievesThePaginatorOptions(): void
    {
        $p = new CursorPaginator(
            $array = [['id' => 4], ['id' => 5], ['id' => 6]],
            2,
            null,
            $options = ['path' => 'http://website.com/test', 'parameters' => ['id']]
        );

        $this->assertSame($p->getOptions(), $options);
    }

    public function testPaginatorReturnsPath(): void
    {
        $p = new CursorPaginator(
            $array = [['id' => 4], ['id' => 5], ['id' => 6]],
            2,
            null,
            $options = ['path' => 'http://website.com/test', 'parameters' => ['id']]
        );

        $this->assertSame($p->path(), 'http://website.com/test');
    }

    public function testCanTransformPaginatorItems(): void
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

    public function testCursorPaginatorOnFirstAndLastPage(): void
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

    public function testItemsAreConsistentlyReindexedForNextAndPreviousPages(): void
    {
        $next = new CursorPaginator([
            10 => ['id' => 1],
            20 => ['id' => 2],
            30 => ['id' => 3],
        ], 2, null, ['parameters' => ['id']]);

        $this->assertSame([0, 1], array_keys($next->items()));

        $previous = new CursorPaginator([
            10 => ['id' => 4],
            20 => ['id' => 3],
            30 => ['id' => 2],
        ], 2, new Cursor(['id' => 5], false), ['parameters' => ['id']]);

        $this->assertSame([0, 1], array_keys($previous->items()));
        $this->assertSame([['id' => 3], ['id' => 4]], $previous->items());
    }

    public function testReturnEmptyCursorWhenItemsAreEmpty(): void
    {
        $cursor = new Cursor(['id' => 25], true);

        $p = new CursorPaginator(new Collection, 25, $cursor, [
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

    public function testCursorPaginatorToJson(): void
    {
        $paginator = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3], ['id' => 4]], 2, null);
        $results = $paginator->toJson();
        $expected = json_encode($paginator->toArray());

        $this->assertJsonStringEqualsJsonString($expected, $results);
        $this->assertSame($expected, $results);
    }

    public function testCursorPaginatorToPrettyJson(): void
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

    public function testNextCursorReturnsCursorObject(): void
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 2, null, [
            'parameters' => ['id'],
        ]);

        $nextCursor = $p->nextCursor();
        $this->assertInstanceOf(Cursor::class, $nextCursor);
        $this->assertTrue($nextCursor->pointsToNextItems());
        $this->assertSame(2, $nextCursor->parameter('id'));
    }

    public function testPreviousCursorReturnsCursorObject(): void
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

    public function testPreviousCursorReturnsNullWhenNoCursor(): void
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2]], 2, null, [
            'parameters' => ['id'],
        ]);

        $this->assertNull($p->previousCursor());
    }

    public function testNextCursorReturnsNullOnLastPage(): void
    {
        $cursor = new Cursor(['id' => 3]);
        $p = new CursorPaginator([['id' => 3], ['id' => 4]], 2, $cursor, [
            'parameters' => ['id'],
        ]);

        $this->assertNull($p->nextCursor());
    }

    public function testGetCursorForItem(): void
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

    public function testGetParametersForItem(): void
    {
        $p = new CursorPaginator([['id' => 1, 'name' => 'a']], 1, null, [
            'parameters' => ['id', 'name'],
        ]);

        $params = $p->getParametersForItem(['id' => 5, 'name' => 'test']);
        $this->assertSame(['id' => 5, 'name' => 'test'], $params);
    }

    #[DataProvider('missingCursorParameterProvider')]
    public function testMissingOrNullCursorParametersThrow(array|object $item, string $parameter): void
    {
        Model::preventAccessingMissingAttributes(false);

        $paginator = new CursorPaginator([$item], 1, null, [
            'parameters' => [$parameter],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage("cursor pagination parameter [{$parameter}]");

        $paginator->getParametersForItem($item);
    }

    public static function missingCursorParameterProvider(): array
    {
        return [
            'missing array value' => [[], 'id'],
            'null array value' => [['id' => null], 'id'],
            'missing aliased array value' => [[], 'users.id'],
            'missing ArrayAccess value' => [new ArrayObject, 'id'],
            'missing object value' => [(object) [], 'id'],
            'missing model value' => [new CursorPaginatorModel, 'id'],
        ];
    }

    #[DataProvider('validCursorParameterProvider')]
    public function testValidFalsyAndStringableCursorParametersArePreserved(mixed $value, mixed $expected): void
    {
        $paginator = new CursorPaginator([['id' => $value]], 1, null, [
            'parameters' => ['id'],
        ]);

        $this->assertSame(['id' => $expected], $paginator->getParametersForItem(['id' => $value]));
    }

    public static function validCursorParameterProvider(): array
    {
        return [
            'integer zero' => [0, 0],
            'string zero' => ['0', '0'],
            'empty string' => ['', ''],
            'stringable value' => [new CursorPaginatorStringableValue('value'), 'value'],
        ];
    }

    public function testMixedPivotCursorParametersArePreserved(): void
    {
        foreach ([7, true, 4.25] as $value) {
            $model = new CursorPaginatorModel;
            $pivot = new Pivot;
            $pivot->setTable('role_user');
            $pivot->setRawAttributes(['position' => $value], true);
            $model->setRelation('membership', $pivot);
            $paginator = new CursorPaginator([$model], 1, null, [
                'parameters' => ['role_user.position'],
            ]);

            $this->assertSame(
                ['role_user.position' => $value],
                $paginator->getParametersForItem($model),
            );
        }
    }

    public function testNullPivotCursorParametersThrow(): void
    {
        $model = new CursorPaginatorModel;
        $pivot = new Pivot;
        $pivot->setTable('role_user');
        $pivot->setRawAttributes(['position' => null], true);
        $model->setRelation('membership', $pivot);
        $paginator = new CursorPaginator([$model], 1, null, [
            'parameters' => ['role_user.position'],
        ]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('cursor pagination parameter [role_user.position]');

        $paginator->getParametersForItem($model);
    }

    public function testFragmentAppearsInUrl(): void
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 2, null, [
            'parameters' => ['id'],
        ]);
        $p->fragment('section');

        $this->assertSame('section', $p->fragment());

        $nextUrl = $p->nextPageUrl();
        $this->assertStringContainsString('#section', $nextUrl);
    }

    public function testAppendsQueryParams(): void
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 2, null, [
            'parameters' => ['id'],
        ]);
        $p->appends('sort', 'name');

        $nextUrl = $p->nextPageUrl();
        $this->assertStringContainsString('sort=name', $nextUrl);
    }

    public function testAppendsPreservesIntegerKeysAndSupportedValues(): void
    {
        $paginator = new CursorPaginator([], 1);
        $paginator->appends([
            2 => 'two',
            5 => 4.25,
            'enabled' => true,
            'filters' => ['status' => 'active'],
        ]);

        $this->assertSame(
            '/?2=two&5=4.25&enabled=1&filters%5Bstatus%5D=active',
            $paginator->url(null),
        );
    }

    public function testCursorReturnsCurrentCursor(): void
    {
        $cursor = new Cursor(['id' => 10], true);
        $p = new CursorPaginator([['id' => 10]], 1, $cursor, [
            'parameters' => ['id'],
        ]);

        $this->assertSame($cursor, $p->cursor());
    }

    public function testCursorReturnsNullWhenNoCursor(): void
    {
        $p = new CursorPaginator([['id' => 1]], 1, null);

        $this->assertNull($p->cursor());
    }

    public function testGetCursorNameAndSetCursorName(): void
    {
        $p = new CursorPaginator([['id' => 1]], 1, null);

        $this->assertSame('cursor', $p->getCursorName());

        $result = $p->setCursorName('page_cursor');
        $this->assertSame($p, $result);
        $this->assertSame('page_cursor', $p->getCursorName());
    }

    public function testIsEmptyAndIsNotEmpty(): void
    {
        $p = new CursorPaginator([], 2, null);
        $this->assertTrue($p->isEmpty());
        $this->assertFalse($p->isNotEmpty());

        $p = new CursorPaginator([['id' => 1]], 2, null);
        $this->assertFalse($p->isEmpty());
        $this->assertTrue($p->isNotEmpty());
    }

    public function testCount(): void
    {
        $p = new CursorPaginator([['id' => 1], ['id' => 2], ['id' => 3]], 3, null);

        $this->assertSame(3, $p->count());
    }

    public function testArrayAccess(): void
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

    public function testCursorPaginatorJsonThrowsForInvalidUtf8(): void
    {
        $paginator = new CursorPaginator([['id' => "\xB1\x31"]], 1);

        $this->expectException(JsonException::class);

        $paginator->toJson();
    }

    public function testCursorPaginatorPrettyJsonPropagatesInvalidUtf8Failure(): void
    {
        $paginator = new CursorPaginator([['id' => "\xB1\x31"]], 1);

        $this->expectException(JsonException::class);

        $paginator->toPrettyJson();
    }

    public function testCursorPaginatorJsonHonorsInvalidUtf8Substitution(): void
    {
        $paginator = new CursorPaginator([['id' => "\xB1\x31"]], 1);

        $this->assertStringContainsString('\ufffd1', $paginator->toJson(JSON_INVALID_UTF8_SUBSTITUTE));
    }

    protected function getCursor(array $params, bool $isNext = true): string
    {
        return (new Cursor($params, $isNext))->encode();
    }
}

class CursorPaginatorModel extends Model
{
}

class CursorPaginatorStringableValue
{
    public function __construct(private readonly string $value)
    {
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
