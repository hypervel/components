<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Pagination;

use Hypervel\Mcp\Server\Pagination\CursorPaginator;
use Hypervel\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;

class CursorPaginatorTest extends TestCase
{
    public function testPaginatesCollectionsCorrectly(): void
    {
        $items = collect([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
            ['name' => 'Item 3'],
            ['name' => 'Item 4'],
            ['name' => 'Item 5'],
        ]);

        $paginator = new CursorPaginator($items, 2);
        $result = $paginator->paginate();

        $this->assertCount(2, $result['items']);
        $this->assertNotNull($result['nextCursor']);
        $this->assertSame([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
        ], $result['items']);
    }

    public function testHandlesCursorBasedPagination(): void
    {
        $items = collect([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
            ['name' => 'Item 3'],
            ['name' => 'Item 4'],
            ['name' => 'Item 5'],
        ]);

        $paginator = new CursorPaginator($items, 2);
        $firstPage = $paginator->paginate();

        $paginator = new CursorPaginator($items, 2, $firstPage['nextCursor']);
        $secondPage = $paginator->paginate();

        $this->assertCount(2, $secondPage['items']);
        $this->assertSame([
            ['name' => 'Item 3'],
            ['name' => 'Item 4'],
        ], $secondPage['items']);
    }

    public function testHandlesLastPageCorrectly(): void
    {
        $items = collect([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
            ['name' => 'Item 3'],
        ]);

        $paginator = new CursorPaginator($items, 5);
        $result = $paginator->paginate();

        $this->assertCount(3, $result['items']);
        $this->assertArrayNotHasKey('nextCursor', $result);
    }

    public function testHandlesInvalidCursorGracefully(): void
    {
        $items = collect([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
        ]);

        $paginator = new CursorPaginator($items, 2, 'invalid-cursor');
        $result = $paginator->paginate();

        $this->assertCount(2, $result['items']);
        $this->assertSame([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
        ], $result['items']);
    }

    #[DataProvider('invalidCursors')]
    public function testFallsBackToTheFirstPageForCursorsThatDoNotDecodeToAnOffset(string $cursor): void
    {
        $items = collect([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
            ['name' => 'Item 3'],
        ]);

        $result = (new CursorPaginator($items, 2, $cursor))->paginate();

        $this->assertSame([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
        ], $result['items']);
    }

    /**
     * Return cursors without a valid offset.
     */
    public static function invalidCursors(): array
    {
        return [
            'non-array JSON' => [base64_encode((string) json_encode(123))],
            'valid base64 but invalid JSON' => [base64_encode('not-json{')],
            'object without an offset key' => [base64_encode((string) json_encode(['foo' => 'bar']))],
        ];
    }

    public function testFallsBackToTheFirstPageForANegativeOffset(): void
    {
        $items = collect([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
            ['name' => 'Item 3'],
        ]);

        $cursor = base64_encode((string) json_encode(['offset' => -1]));

        $result = (new CursorPaginator($items, 2, $cursor))->paginate();

        $this->assertSame([
            ['name' => 'Item 1'],
            ['name' => 'Item 2'],
        ], $result['items']);
    }

    #[DataProvider('invalidPageSizes')]
    public function testRejectsPageSizesThatCannotAdvance(int $perPage): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The page size must be a positive integer.');

        new CursorPaginator(collect(['item']), $perPage);
    }

    /**
     * Return page sizes that cannot advance the cursor.
     */
    public static function invalidPageSizes(): array
    {
        return [[0], [-1]];
    }
}
