<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Tests\Pagination\Fixtures\Models\PaginatorResourceTestModel;
use Hypervel\Tests\TestCase;
use LogicException;

/**
 * @internal
 * @coversNothing
 */
class PaginatorResourceTest extends TestCase
{
    public function testItCanTransformToExplicitResource()
    {
        $paginator = new PaginatorResourceTestPaginator([
            new PaginatorResourceTestModel(),
        ], 1, 1, 1);

        $resource = $paginator->toResourceCollection(PaginatorResourceTestResource::class);

        $this->assertInstanceOf(JsonResource::class, $resource);
    }

    public function testItThrowsExceptionWhenResourceCannotBeFound()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Failed to find resource class for model [Hypervel\Tests\Pagination\Fixtures\Models\PaginatorResourceTestModel].');

        $paginator = new PaginatorResourceTestPaginator([
            new PaginatorResourceTestModel(),
        ], 1, 1, 1);

        $paginator->toResourceCollection();
    }

    public function testItCanGuessResourceWhenNotProvided()
    {
        $paginator = new PaginatorResourceTestPaginator([
            new PaginatorResourceTestModel(),
        ], 1, 1, 1);

        class_alias(PaginatorResourceTestResource::class, 'Hypervel\Tests\Pagination\Fixtures\Http\Resources\PaginatorResourceTestModelResource');

        $resource = $paginator->toResourceCollection();

        $this->assertInstanceOf(JsonResource::class, $resource);
    }
}

class PaginatorResourceTestResource extends JsonResource
{
}

class PaginatorResourceTestPaginator extends LengthAwarePaginator
{
}
