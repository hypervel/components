<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data;

use Hypervel\Contracts\Database\Eloquent\Castable;
use Hypervel\Data\Contracts\ResponsableData;
use Hypervel\Data\Contracts\TransformableData;
use Hypervel\Data\CursorPaginatedDataCollection;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\Dto;
use Hypervel\Data\PaginatedDataCollection;
use Hypervel\Data\Resource;
use Hypervel\Tests\TestCase;

class CapabilityTest extends TestCase
{
    /**
     * Test each public base class exposes only its supported capabilities.
     */
    public function testBaseClassCapabilities(): void
    {
        $this->assertTrue(is_a(Data::class, TransformableData::class, true));
        $this->assertTrue(is_a(Data::class, Castable::class, true));
        $this->assertTrue(is_a(Data::class, ResponsableData::class, true));
        $this->assertTrue(is_a(Resource::class, TransformableData::class, true));
        $this->assertTrue(is_a(Resource::class, Castable::class, true));
        $this->assertTrue(is_a(Resource::class, ResponsableData::class, true));
        $this->assertFalse(is_a(Dto::class, TransformableData::class, true));
        $this->assertFalse(is_a(Dto::class, Castable::class, true));
        $this->assertFalse(is_a(Dto::class, ResponsableData::class, true));
    }

    /**
     * Test paginator wrappers remain transformable without claiming persistence support.
     */
    public function testCollectionCapabilities(): void
    {
        $this->assertTrue(is_a(DataCollection::class, TransformableData::class, true));
        $this->assertTrue(is_a(DataCollection::class, Castable::class, true));
        $this->assertTrue(is_a(DataCollection::class, ResponsableData::class, true));
        $this->assertTrue(is_a(PaginatedDataCollection::class, TransformableData::class, true));
        $this->assertFalse(is_a(PaginatedDataCollection::class, Castable::class, true));
        $this->assertTrue(is_a(PaginatedDataCollection::class, ResponsableData::class, true));
        $this->assertTrue(is_a(CursorPaginatedDataCollection::class, TransformableData::class, true));
        $this->assertFalse(is_a(CursorPaginatedDataCollection::class, Castable::class, true));
        $this->assertTrue(is_a(CursorPaginatedDataCollection::class, ResponsableData::class, true));
    }
}
