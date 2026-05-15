<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Resources;

use Hypervel\Http\Resources\JsonApi\JsonApiResource;
use Hypervel\Tests\TestCase;

class JsonApiResourceTest extends TestCase
{
    public function testFlushStateRestoresDefaultRelationshipDepth(): void
    {
        $this->assertSame(5, JsonApiResource::$maxRelationshipDepth);

        JsonApiResource::maxRelationshipDepth(2);

        $this->assertSame(2, JsonApiResource::$maxRelationshipDepth);

        JsonApiResource::flushState();

        $this->assertSame(5, JsonApiResource::$maxRelationshipDepth);
    }
}
