<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\Json;

use Hypervel\Foundation\Auth\User;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\ResourceCollection;
use Hypervel\Support\Fluent;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ResourceCollectionTest extends TestCase
{
    #[DataProvider('toArrayDataProvider')]
    public function testItCanReturnToArray(ResourceCollection $collection, mixed $expected): void
    {
        $request = Request::create('GET', '/');

        $this->assertSame($expected, $collection->toArray($request));
    }

    public static function toArrayDataProvider(): iterable
    {
        yield [
            new ResourceCollection([
                new Fluent(['id' => 1]),
                new Fluent(['id' => 2]),
                new Fluent(['id' => 3]),
            ]),
            [
                ['id' => 1],
                ['id' => 2],
                ['id' => 3],
            ],
        ];

        yield [
            (new ResourceCollection([
                (new User)->forceFill(['name' => 'Taylor Otwell']),
                (new User)->forceFill(['name' => 'Hypervel']),
            ]))->additional(['total', 1]),
            [
                ['name' => 'Taylor Otwell'],
                ['name' => 'Hypervel'],
            ],
        ];

        yield [
            new class(['list' => new Fluent(['id' => 1]), 'total' => 1]) extends ResourceCollection {
                public function toArray(Request $request): array
                {
                    return $this->resource->toArray();
                }
            },
            [
                'list' => ['id' => 1],
                'total' => 1,
            ],
        ];
    }
}
