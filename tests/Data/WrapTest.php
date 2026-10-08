<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\WrapTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Http\Request;
use Hypervel\Http\Resources\Json\JsonResource;
use Hypervel\Http\Resources\Json\ResourceCollection;
use Hypervel\Support\Facades\Route;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\TestResponse;
use Hypervel\Tests\Data\Fixtures\MultiNestedData;
use Hypervel\Tests\Data\Fixtures\NestedData;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithWrap;

class WrapTest extends TestCase
{
    /**
     * Get package providers for the wrap test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanWrapDataObjectsByMethodCall(): void
    {
        $this->assertSame(
            ['wrap' => ['string' => 'Hello World']],
            SimpleData::from('Hello World')
                ->wrap('wrap')
                ->toResponse(Request::create('/'))
                ->getData(true),
        );

        $this->assertSame([
            'wrap' => [
                ['string' => 'Hello'],
                ['string' => 'World'],
            ],
        ], SimpleData::collect(['Hello', 'World'], DataCollection::class)
            ->wrap('wrap')
            ->toResponse(Request::create('/'))
            ->getData(true));
    }

    #[WithConfig('data.wrap', 'wrap')]
    public function testCanWrapDataObjectsUsingAGlobalDefault(): void
    {
        $this->assertSame(
            ['wrap' => ['string' => 'Hello World']],
            SimpleData::from('Hello World')->toResponse(Request::create('/'))->getData(true),
        );

        $this->assertSame(
            ['other-wrap' => ['string' => 'Hello World']],
            SimpleData::from('Hello World')->wrap('other-wrap')->toResponse(Request::create('/'))->getData(true),
        );

        $this->assertSame(
            ['string' => 'Hello World'],
            SimpleData::from('Hello World')->withoutWrapping()->toResponse(Request::create('/'))->getData(true),
        );

        $this->assertSame([
            'wrap' => [
                ['string' => 'Hello'],
                ['string' => 'World'],
            ],
        ], SimpleData::collect(['Hello', 'World'], DataCollection::class)->toResponse(Request::create('/'))->getData(true));

        $this->assertSame(
            ['string' => 'Hello World'],
            SimpleData::from('Hello World')->withoutWrapping()->toResponse(Request::create('/'))->getData(true),
        );

        $this->assertSame([
            'other-wrap' => [
                ['string' => 'Hello'],
                ['string' => 'World'],
            ],
        ], (new DataCollection(SimpleData::class, ['Hello', 'World']))
            ->wrap('other-wrap')
            ->toResponse(Request::create('/'))
            ->getData(true));

        $this->assertSame([
            ['string' => 'Hello'],
            ['string' => 'World'],
        ], (new DataCollection(SimpleData::class, ['Hello', 'World']))
            ->withoutWrapping()
            ->toResponse(Request::create('/'))
            ->getData(true));
    }

    public function testCanSetADefaultWrapOnADataObject(): void
    {
        $this->assertSame(
            ['wrap' => ['string' => 'Hello World']],
            SimpleDataWithWrap::from('Hello World')->toResponse(Request::create('/'))->getData(true),
        );

        $this->assertSame(
            ['other-wrap' => ['string' => 'Hello World']],
            SimpleDataWithWrap::from('Hello World')->wrap('other-wrap')->toResponse(Request::create('/'))->getData(true),
        );

        $this->assertSame(
            ['string' => 'Hello World'],
            SimpleDataWithWrap::from('Hello World')->withoutWrapping()->toResponse(Request::create('/'))->getData(true),
        );
    }

    public function testWrapsAdditionalData(): void
    {
        $dataClass = new class('Hello World') extends Data {
            /**
             * Create a data object with a string.
             */
            public function __construct(
                public string $string
            ) {
            }

            /**
             * Get the data appended to the output.
             */
            public function with(): array
            {
                return ['with' => 'this'];
            }
        };

        $data = $dataClass->additional(['additional' => 'this'])
            ->wrap('wrap')
            ->toResponse(Request::create('/'))
            ->getData(true);

        $this->assertSame([
            'wrap' => ['string' => 'Hello World'],
            'with' => 'this',
            'additional' => 'this',
        ], $data);
    }

    public function testWrapsComplexDataStructures(): void
    {
        $data = new MultiNestedData(
            new NestedData(SimpleData::from('Hello')),
            [
                new NestedData(SimpleData::from('World')),
            ],
        );

        $this->assertSame([
            'wrap' => [
                'nested' => ['simple' => ['string' => 'Hello']],
                'nestedCollection' => [
                    ['simple' => ['string' => 'World']],
                ],
            ],
        ], $data->wrap('wrap')->toResponse(Request::create('/'))->getData(true));
    }

    #[WithConfig('data.wrap', 'wrap')]
    public function testWrapsComplexDataStructuresWithAGlobal(): void
    {
        $data = new MultiNestedData(
            new NestedData(SimpleData::from('Hello')),
            [
                new NestedData(SimpleData::from('World')),
            ],
        );

        $this->assertSame([
            'wrap' => [
                'nested' => ['simple' => ['string' => 'Hello']],
                'nestedCollection' => [
                    'wrap' => [
                        ['simple' => ['string' => 'World']],
                    ],
                ],
            ],
        ], $data->wrap('wrap')->toResponse(Request::create('/'))->getData(true));
    }

    public function testOnlyWrapsResponsesDefaultTransformationsWillNotWrap(): void
    {
        $this->assertSame(['string' => 'Hello World'], SimpleData::from('Hello World')->wrap('wrap')->toArray());

        $this->assertSame([
            ['string' => 'Hello'],
            ['string' => 'World'],
        ], SimpleData::collect(['Hello', 'World'], DataCollection::class)->wrap('wrap')->toArray());
    }

    public function testWillWrapResponsesWhichAreData(): void
    {
        Route::post('/example-route', function (): SimpleData {
            return SimpleData::from(request()->input('string'))->wrap('data');
        });

        // Responses use 200 for every method; Spatie returns 201 for every POST (README).
        $this->performRequest('Hello World')
            ->assertOk()
            ->assertJson(['data' => ['string' => 'Hello World']]);
    }

    public function testWillWrapResponsesWhichAreDataCollections(): void
    {
        Route::post('/example-route', function (): DataCollection {
            return SimpleData::collect([
                request()->input('string'),
                strtoupper(request()->input('string')),
            ], DataCollection::class)->wrap('data');
        });

        $this->performRequest('Hello World')
            ->assertOk()
            ->assertJson([
                'data' => [
                    ['string' => 'Hello World'],
                    ['string' => 'HELLO WORLD'],
                ],
            ]);
    }

    public function testCheckHypervelFunctionality(): void
    {
        Route::post('/resource', function (): TestResource {
            return TestResource::make([]);
        });

        Route::post('/collection', function (): TestResourceCollection {
            return new TestResourceCollection([
                [],
                [],
            ]);
        });

        $this->withoutExceptionHandling();

        $expectedResource = [
            'id' => 1,
            'nested' => [
                'id' => 2,
            ],
            'nested_collection' => [
                ['id' => 3],
                ['id' => 4],
            ],
            'nested_collection_object' => [
                ['id' => 5],
                ['id' => 6],
            ],
        ];

        $this->post('/resource')
            ->assertExactJson(['data' => $expectedResource]);

        $this->post('/collection')
            ->assertExactJson([
                'data' => [
                    $expectedResource,
                    $expectedResource,
                ],
            ]);
    }

    /**
     * Post a string to the example route.
     */
    protected function performRequest(string $string): TestResponse
    {
        return $this->postJson('/example-route', [
            'string' => $string,
        ]);
    }
}

class TestEmbeddedResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this['id'],
        ];
    }
}

class TestEmbeddedResourceCollection extends ResourceCollection
{
    public ?string $collects = TestEmbeddedResource::class;
}

class TestResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => 1,
            'nested' => TestEmbeddedResource::make(['id' => 2]),
            'nested_collection' => TestEmbeddedResource::collection([
                ['id' => 3],
                ['id' => 4],
            ]),
            'nested_collection_object' => new TestEmbeddedResourceCollection([
                ['id' => 5],
                ['id' => 6],
            ]),
        ];
    }
}

class TestResourceCollection extends ResourceCollection
{
    public ?string $collects = TestResource::class;
}
