<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Resource;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\AnonymousResourceCollection;
use Hypervel\Http\Resources\Json\JsonResource;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class JsonResourceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testAnonymousResourceCollection()
    {
        $resource = new class {
            public function toArray()
            {
                return ['foo' => 'bar'];
            }
        };

        $collection = JsonResource::collection([$resource]);
        $request = Mockery::mock(Request::class);

        $this->assertInstanceOf(AnonymousResourceCollection::class, $collection);
        $this->assertSame([['foo' => 'bar']], $collection->toArray($request));
    }
}
