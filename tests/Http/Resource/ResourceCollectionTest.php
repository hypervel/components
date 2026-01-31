<?php

declare(strict_types=1);

namespace Hypervel\Tests\Http\Resource;

use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\ResourceCollection;
use Mockery;
use PHPUnit\Framework\TestCase;

/**
 * @internal
 * @coversNothing
 */
class ResourceCollectionTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
    }

    public function testResourceCollection()
    {
        $resourceA = new class {
            public function toArray()
            {
                return ['foo' => 'bar'];
            }
        };
        $resourceB = new class {
            public function toArray()
            {
                return ['hello' => 'world'];
            }
        };

        $collection = new ResourceCollection([$resourceA, $resourceB]);
        $request = Mockery::mock(Request::class);

        $this->assertSame(
            [
                ['foo' => 'bar'],
                ['hello' => 'world'],
            ],
            $collection->toArray($request)
        );
    }
}
