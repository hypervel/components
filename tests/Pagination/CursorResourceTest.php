<?php

declare(strict_types=1);

namespace Hypervel\Tests\Pagination;

use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Tests\Pagination\Fixtures\Models\CursorResourceTestModel;
use Hypervel\Tests\TestCase;
use LogicException;

class CursorResourceTest extends TestCase
{
    public function testItCanTransformToExplicitResource()
    {
        $paginator = new CursorResourceTestPaginator([
            new CursorResourceTestModel,
        ], 1);

        $resource = $paginator->toResourceCollection(CursorResourceTestResource::class);

        $this->assertInstanceOf(JsonResource::class, $resource);
    }

    public function testItThrowsExceptionWhenResourceCannotBeFound()
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Failed to find resource class for model [Hypervel\Tests\Pagination\Fixtures\Models\CursorResourceTestModel].');

        $paginator = new CursorResourceTestPaginator([
            new CursorResourceTestModel,
        ], 1);

        $paginator->toResourceCollection();
    }

    public function testItCanGuessResourceWhenNotProvided()
    {
        $paginator = new CursorResourceTestPaginator([
            new CursorResourceTestModel,
        ], 1);

        class_alias(CursorResourceTestResource::class, 'Hypervel\Tests\Pagination\Fixtures\Http\Resources\CursorResourceTestModelResource');

        $resource = $paginator->toResourceCollection();

        $this->assertInstanceOf(JsonResource::class, $resource);
    }
}

class CursorResourceTestResource extends JsonResource
{
}

class CursorResourceTestPaginator extends CursorPaginator
{
}
