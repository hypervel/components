<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\PartialsTest;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapName;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Http\RequestQueryStringPartialsResolver;
use Hypervel\Data\Lazy;
use Hypervel\Data\Mappers\SnakeCaseMapper;
use Hypervel\Data\Support\Partials\PartialDefinition;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Http\Request;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Support\Collection;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\CircData;
use Hypervel\Tests\Data\Fixtures\DefaultLazyData;
use Hypervel\Tests\Data\Fixtures\DummyDto;
use Hypervel\Tests\Data\Fixtures\ExceptData;
use Hypervel\Tests\Data\Fixtures\LazyData;
use Hypervel\Tests\Data\Fixtures\Models\FakeModel;
use Hypervel\Tests\Data\Fixtures\MultiData;
use Hypervel\Tests\Data\Fixtures\MultiLazyData;
use Hypervel\Tests\Data\Fixtures\NestedLazyData;
use Hypervel\Tests\Data\Fixtures\OnlyData;
use Hypervel\Tests\Data\Fixtures\PartialClassConditionalData;
use Hypervel\Tests\Data\Fixtures\SimpleChildDataWithMappedOutputName;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\UlarData;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * These are the more special partial tests, such as including through the request and conditions.
 * For unit tests of the partials themselves, see VisibleDataFieldsResolverTest.
 */
class PartialsTest extends TestCase
{
    /**
     * Get package providers for the partials test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    public function testCanDynamicallyIncludeDataBasedUponTheRequest(): void
    {
        LazyData::setAllowedIncludes([]);

        $response = LazyData::from('Ruben')->toResponse(Request::create('/'));

        $this->assertSame([], $response->getData(true));

        LazyData::setAllowedIncludes(['name']);

        $includedResponse = LazyData::from('Ruben')->toResponse(Request::create('/', 'GET', [
            'include' => 'name',
        ]));

        $this->assertSame(['name' => 'Ruben'], $includedResponse->getData(true));
    }

    public function testCanDisabledIncludingDataDynamicallyFromTheRequest(): void
    {
        LazyData::setAllowedIncludes([]);

        $response = LazyData::from('Ruben')->toResponse(Request::create('/', 'GET', [
            'include' => 'name',
        ]));

        $this->assertSame([], $response->getData(true));

        LazyData::setAllowedIncludes(['name']);

        $response = LazyData::from('Ruben')->toResponse(Request::create('/', 'GET', [
            'include' => 'name',
        ]));

        $this->assertSame(['name' => 'Ruben'], $response->getData(true));

        LazyData::setAllowedIncludes(null);

        $response = LazyData::from('Ruben')->toResponse(Request::create('/', 'GET', [
            'include' => 'name',
        ]));

        $this->assertSame(['name' => 'Ruben'], $response->getData(true));
    }

    public function testCanDynamicallyExcludeDataBasedUponTheRequest(): void
    {
        DefaultLazyData::setAllowedExcludes([]);

        $response = DefaultLazyData::from('Ruben')->toResponse(Request::create('/'));

        $this->assertSame(['name' => 'Ruben'], $response->getData(true));

        DefaultLazyData::setAllowedExcludes(['name']);

        $excludedResponse = DefaultLazyData::from('Ruben')->toResponse(Request::create('/', 'GET', [
            'exclude' => 'name',
        ]));

        $this->assertSame([], $excludedResponse->getData(true));
    }

    public function testCanDisableExcludingDataDynamicallyFromTheRequest(): void
    {
        DefaultLazyData::setAllowedExcludes([]);

        $response = DefaultLazyData::from('Ruben')->toResponse(Request::create('/', 'GET', [
            'exclude' => 'name',
        ]));

        $this->assertSame(['name' => 'Ruben'], $response->getData(true));

        DefaultLazyData::setAllowedExcludes(['name']);

        $response = DefaultLazyData::from('Ruben')->toResponse(Request::create('/', 'GET', [
            'exclude' => 'name',
        ]));

        $this->assertSame([], $response->getData(true));

        DefaultLazyData::setAllowedExcludes(null);

        $response = DefaultLazyData::from('Ruben')->toResponse(Request::create('/', 'GET', [
            'exclude' => 'name',
        ]));

        $this->assertSame([], $response->getData(true));
    }

    public function testCanDisableOnlyDataDynamicallyFromTheRequest(): void
    {
        OnlyData::setAllowedOnly([]);

        $response = OnlyData::from([
            'first_name' => 'Ruben',
            'last_name' => 'Van Assche',
        ])->toResponse(Request::create('/', 'GET', [
            'only' => 'first_name',
        ]));

        $this->assertSame([
            'first_name' => 'Ruben',
            'last_name' => 'Van Assche',
        ], $response->getData(true));

        OnlyData::setAllowedOnly(['first_name']);

        $response = OnlyData::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche'])->toResponse(Request::create('/', 'GET', [
            'only' => 'first_name',
        ]));

        $this->assertSame([
            'first_name' => 'Ruben',
        ], $response->getData(true));

        OnlyData::setAllowedOnly(null);

        $response = OnlyData::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche'])->toResponse(Request::create('/', 'GET', [
            'only' => 'first_name',
        ]));

        $this->assertSame([
            'first_name' => 'Ruben',
        ], $response->getData(true));
    }

    public function testCanDisableExceptDataDynamicallyFromTheRequest(): void
    {
        ExceptData::setAllowedExcept([]);

        $response = ExceptData::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche'])->toResponse(Request::create('/', 'GET', [
            'except' => 'first_name',
        ]));

        $this->assertSame([
            'first_name' => 'Ruben',
            'last_name' => 'Van Assche',
        ], $response->getData(true));

        ExceptData::setAllowedExcept(['first_name']);

        $response = ExceptData::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche'])->toResponse(Request::create('/', 'GET', [
            'except' => 'first_name',
        ]));

        $this->assertSame([
            'last_name' => 'Van Assche',
        ], $response->getData(true));

        ExceptData::setAllowedExcept(null);

        $response = ExceptData::from(['first_name' => 'Ruben', 'last_name' => 'Van Assche'])->toResponse(Request::create('/', 'GET', [
            'except' => 'first_name',
        ]));

        $this->assertSame([
            'last_name' => 'Van Assche',
        ], $response->getData(true));
    }

    public function testCanConditionallyInclude(): void
    {
        $this->assertSame(
            [],
            MultiLazyData::from(DummyDto::rick())->includeWhen('artist', false)->toArray(),
        );

        $this->assertSame([
            'artist' => 'Rick Astley',
        ], MultiLazyData::from(DummyDto::rick())
            ->includeWhen('artist', true)
            ->toArray());

        $this->assertSame([
            'name' => 'Never gonna give you up',
        ], MultiLazyData::from(DummyDto::rick())
            ->includeWhen('name', fn (MultiLazyData $data): bool => $data->artist->resolve() === 'Rick Astley')
            ->toArray());
    }

    public function testCanConditionallyIncludeNested(): void
    {
        $data = new class extends Data {
            public NestedLazyData $nested;
        };

        $data->nested = NestedLazyData::from('Hello World');

        $this->assertSame(['nested' => []], $data->toArray());

        $this->assertSame([
            'nested' => ['simple' => ['string' => 'Hello World']],
        ], $data->includeWhen('nested.simple', true)->toArray());
    }

    public function testCanConditionallyIncludeNestedCollection(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(MultiLazyData::class)]
            public Collection $nested;
        };

        $data = $dataClass::collect([
            [
                'nested' => [DummyDto::rick()],
            ], [
                'nested' => [DummyDto::bon()],
            ],
        ], DataCollection::class);

        $this->assertSame([
            ['nested' => [[]]],
            ['nested' => [[]]],
        ], $data->toArray());

        $this->assertSame([
            ['nested' => [['artist' => DummyDto::rick()->artist, 'year' => DummyDto::rick()->year]]],
            ['nested' => [['artist' => DummyDto::bon()->artist, 'year' => DummyDto::bon()->year]]],
        ], $data->include('nested.{artist,year}')->toArray());
    }

    public function testCanConditionallyIncludeUsingClassDefaults(): void
    {
        PartialClassConditionalData::setDefinitions(includeDefinitions: [
            'string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame(['enabled' => false], PartialClassConditionalData::createLazy(enabled: false)->toArray());

        $this->assertSame(
            ['enabled' => true, 'string' => 'Hello World'],
            PartialClassConditionalData::createLazy(enabled: true)->toArray(),
        );
    }

    public function testCanConditionallyIncludeUsingClassDefaultsNested(): void
    {
        PartialClassConditionalData::setDefinitions(includeDefinitions: [
            'nested.string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame(
            ['enabled' => true, 'nested' => ['string' => 'Hello World']],
            PartialClassConditionalData::createLazy(enabled: true)->toArray(),
        );
    }

    public function testCanConditionallyIncludeUsingClassDefaultsMultiple(): void
    {
        PartialClassConditionalData::setDefinitions(includeDefinitions: [
            'nested.string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
            'string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame(['enabled' => false], PartialClassConditionalData::createLazy(enabled: false)->toArray());

        $this->assertSame([
            'enabled' => true,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::createLazy(enabled: true)->toArray());
    }

    public function testCanConditionallyExclude(): void
    {
        $data = new MultiLazyData(
            Lazy::create(fn (): string => 'Rick Astley')->defaultIncluded(),
            Lazy::create(fn (): string => 'Never gonna give you up')->defaultIncluded(),
            1989
        );

        $this->assertSame([
            'artist' => 'Rick Astley',
            'name' => 'Never gonna give you up',
            'year' => 1989,
        ], (clone $data)->exceptWhen('artist', false)->toArray());

        $this->assertSame([
            'name' => 'Never gonna give you up',
            'year' => 1989,
        ], (clone $data)->exceptWhen('artist', true)->toArray());

        $this->assertSame([
            'artist' => 'Rick Astley',
            'year' => 1989,
        ], (clone $data)
            ->exceptWhen('name', fn (MultiLazyData $data): bool => $data->artist->resolve() === 'Rick Astley')
            ->toArray());
    }

    public function testCanConditionallyExcludeNested(): void
    {
        $data = new class extends Data {
            public NestedLazyData $nested;
        };

        $data->nested = new NestedLazyData(Lazy::create(fn (): SimpleData => SimpleData::from('Hello World'))->defaultIncluded());

        $this->assertSame([
            'nested' => ['simple' => ['string' => 'Hello World']],
        ], $data->toArray());

        $this->assertSame(['nested' => []], $data->exceptWhen('nested.simple', true)->toArray());
    }

    public function testCanConditionallyExcludeUsingClassDefaults(): void
    {
        PartialClassConditionalData::setDefinitions(excludeDefinitions: [
            'string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame([
            'enabled' => false,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::createDefaultIncluded(enabled: false)->toArray());

        $this->assertSame([
            'enabled' => true,
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::createDefaultIncluded(enabled: true)->toArray());
    }

    public function testCanConditionallyExcludeUsingClassDefaultsNested(): void
    {
        PartialClassConditionalData::setDefinitions(excludeDefinitions: [
            'nested.string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame([
            'enabled' => false,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::createDefaultIncluded(enabled: false)->toArray());

        // Exclusions only hide lazy properties, and the nested string isn't lazy.
        $this->assertSame([
            'enabled' => true,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::createDefaultIncluded(enabled: true)->toArray());
    }

    public function testCanConditionallyExcludeUsingMultipleClassDefaults(): void
    {
        PartialClassConditionalData::setDefinitions(excludeDefinitions: [
            'string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
            'nested.string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame([
            'enabled' => false,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::createDefaultIncluded(enabled: false)->toArray());

        // Exclusions only hide lazy properties, and the nested string isn't lazy.
        $this->assertSame([
            'enabled' => true,
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::createDefaultIncluded(enabled: true)->toArray());
    }

    public function testCanConditionallyDefineOnly(): void
    {
        $data = new MultiData('Hello', 'World');

        $this->assertSame([
            'first' => 'Hello',
        ], (clone $data)->onlyWhen('first', true)->toArray());

        $this->assertSame([
            'first' => 'Hello',
            'second' => 'World',
        ], (clone $data)->onlyWhen('first', false)->toArray());

        $this->assertSame(['second' => 'World'], (clone $data)
            ->onlyWhen('second', fn (MultiData $data): bool => $data->second === 'World')
            ->toArray());

        $this->assertSame([
            'first' => 'Hello',
            'second' => 'World',
        ], (clone $data)
            ->onlyWhen('first', fn (MultiData $data): bool => $data->first === 'Hello')
            ->onlyWhen('second', fn (MultiData $data): bool => $data->second === 'World')
            ->toArray());
    }

    public function testCanConditionallyDefineOnlyNested(): void
    {
        $data = new class extends Data {
            public MultiData $nested;
        };

        $data->nested = new MultiData('Hello', 'World');

        $this->assertSame([
            'nested' => ['first' => 'Hello'],
        ], (clone $data)->onlyWhen('nested.first', true)->toArray());

        $this->assertSame([
            'nested' => [
                'first' => 'Hello',
                'second' => 'World',
            ],
        ], (clone $data)->onlyWhen('nested.{first, second}', true)->toArray());
    }

    public function testCanConditionallyDefineOnlyUsingClassDefaults(): void
    {
        PartialClassConditionalData::setDefinitions(onlyDefinitions: [
            'string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame([
            'enabled' => false,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::create(enabled: false)->toArray());

        $this->assertSame(['string' => 'Hello World'], PartialClassConditionalData::create(enabled: true)->toArray());
    }

    public function testCanConditionallyDefineOnlyUsingClassDefaultsNested(): void
    {
        PartialClassConditionalData::setDefinitions(onlyDefinitions: [
            'nested.string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame([
            'enabled' => false,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::create(enabled: false)->toArray());

        $this->assertSame([
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::create(enabled: true)->toArray());
    }

    public function testCanConditionallyDefineOnlyUsingMultipleClassDefaults(): void
    {
        PartialClassConditionalData::setDefinitions(onlyDefinitions: [
            'string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
            'nested.string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame([
            'enabled' => false,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::create(enabled: false)->toArray());

        $this->assertSame([
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::create(enabled: true)->toArray());
    }

    public function testCanConditionallyDefineExcept(): void
    {
        $data = new MultiData('Hello', 'World');

        $this->assertSame(['second' => 'World'], (clone $data)->exceptWhen('first', true)->toArray());

        $this->assertSame([
            'first' => 'Hello',
            'second' => 'World',
        ], (clone $data)->exceptWhen('first', false)->toArray());

        $this->assertSame([
            'first' => 'Hello',
        ], (clone $data)
            ->exceptWhen('second', fn (MultiData $data): bool => $data->second === 'World')
            ->toArray());

        $this->assertSame([], (clone $data)
            ->exceptWhen('first', fn (MultiData $data): bool => $data->first === 'Hello')
            ->exceptWhen('second', fn (MultiData $data): bool => $data->second === 'World')
            ->toArray());
    }

    public function testCanConditionallyDefineExceptNested(): void
    {
        $data = new class extends Data {
            public MultiData $nested;
        };

        $data->nested = new MultiData('Hello', 'World');

        $this->assertSame(['nested' => ['second' => 'World']], (clone $data)->exceptWhen('nested.first', true)->toArray());

        $this->assertSame(['nested' => []], (clone $data)->exceptWhen('nested.{first, second}', true)->toArray());
    }

    public function testCanConditionallyDefineExceptUsingClassDefaults(): void
    {
        PartialClassConditionalData::setDefinitions(exceptDefinitions: [
            'string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame([
            'enabled' => false,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::create(enabled: false)->toArray());

        $this->assertSame([
            'enabled' => true,
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::create(enabled: true)->toArray());
    }

    public function testCanConditionallyDefineExceptUsingClassDefaultsNested(): void
    {
        PartialClassConditionalData::setDefinitions(exceptDefinitions: [
            'nested.string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame([
            'enabled' => false,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::create(enabled: false)->toArray());

        $this->assertSame([
            'enabled' => true,
            'string' => 'Hello World',
            'nested' => [],
        ], PartialClassConditionalData::create(enabled: true)->toArray());
    }

    public function testCanConditionallyDefineExceptUsingMultipleClassDefaults(): void
    {
        PartialClassConditionalData::setDefinitions(exceptDefinitions: [
            'string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
            'nested.string' => fn (PartialClassConditionalData $data): bool => $data->enabled,
        ]);

        $this->assertSame([
            'enabled' => false,
            'string' => 'Hello World',
            'nested' => ['string' => 'Hello World'],
        ], PartialClassConditionalData::create(enabled: false)->toArray());

        $this->assertSame([
            'enabled' => true,
            'nested' => [],
        ], PartialClassConditionalData::create(enabled: true)->toArray());
    }

    public function testCanPerformOnlyAndExceptOnArrayProperties(): void
    {
        $data = new class('Hello World', ['string' => 'Hello World', 'int' => 42]) extends Data {
            /**
             * Create a data object with a string and an array.
             */
            public function __construct(
                public string $string,
                public array $array
            ) {
            }
        };

        $this->assertSame([
            'string' => 'Hello World',
            'array' => ['int' => 42],
        ], (clone $data)->only('string', 'array.int')->toArray());

        $this->assertSame([
            'array' => ['string' => 'Hello World'],
        ], (clone $data)->except('string', 'array.int')->toArray());
    }

    public function testCanFetchLazyPropertiesLikeRegularPropertiesWithinPhp(): void
    {
        $dataClass = new class extends Data {
            public int $id;

            public SimpleData|Lazy $simple;

            #[DataCollectionOf(SimpleData::class)]
            public DataCollection|Lazy $dataCollection;

            public FakeModel|Lazy $fakeModel;
        };

        $data = $dataClass::from([
            'id' => 42,
            'simple' => Lazy::create(fn (): SimpleData => SimpleData::from('A')),
            'dataCollection' => Lazy::create(fn (): DataCollection => SimpleData::collect(['B', 'C'], DataCollection::class)),
            // Upstream persists the model; reading it through the lazy property needs no database.
            'fakeModel' => Lazy::create(fn (): FakeModel => FakeModel::factory()->make([
                'string' => 'lazy',
            ])),
        ]);

        $this->assertSame(42, $data->id);
        $this->assertSame('A', $data->simple->string);
        $this->assertSame(['B', 'C'], $data->dataCollection->toCollection()->pluck('string')->toArray());
        $this->assertSame('lazy', $data->fakeModel->string);
    }

    public function testHasArrayAccessAndWillReplicatePartialsCollection(): void
    {
        $collection = MultiData::collect([
            new MultiData('first', 'second'),
        ], DataCollection::class)->only('second');

        $this->assertSame(['second' => 'second'], $collection[0]->toArray());
    }

    public function testCanDynamicallyIncludeDataBasedUponTheRequestCollection(): void
    {
        LazyData::setAllowedIncludes(['']);

        $response = (new DataCollection(LazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/'));

        $this->assertSame([
            [],
            [],
            [],
        ], $response->getData(true));

        LazyData::setAllowedIncludes(['name']);

        $includedResponse = (new DataCollection(LazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/', 'GET', [
            'include' => 'name',
        ]));

        $this->assertSame([
            ['name' => 'Ruben'],
            ['name' => 'Freek'],
            ['name' => 'Brent'],
        ], $includedResponse->getData(true));
    }

    public function testCanDisableManuallyIncludingDataInTheRequestCollection(): void
    {
        LazyData::setAllowedIncludes([]);

        $response = (new DataCollection(LazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/', 'GET', [
            'include' => 'name',
        ]));

        $this->assertSame([
            [],
            [],
            [],
        ], $response->getData(true));

        LazyData::setAllowedIncludes(['name']);

        $response = (new DataCollection(LazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/', 'GET', [
            'include' => 'name',
        ]));

        $this->assertSame([
            ['name' => 'Ruben'],
            ['name' => 'Freek'],
            ['name' => 'Brent'],
        ], $response->getData(true));

        LazyData::setAllowedIncludes(null);

        $response = (new DataCollection(LazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/', 'GET', [
            'include' => 'name',
        ]));

        $this->assertSame([
            ['name' => 'Ruben'],
            ['name' => 'Freek'],
            ['name' => 'Brent'],
        ], $response->getData(true));
    }

    public function testCanDynamicallyExcludeDataBasedUponTheRequestCollection(): void
    {
        DefaultLazyData::setAllowedExcludes([]);

        $response = (new DataCollection(DefaultLazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/'));

        $this->assertSame([
            ['name' => 'Ruben'],
            ['name' => 'Freek'],
            ['name' => 'Brent'],
        ], $response->getData(true));

        DefaultLazyData::setAllowedExcludes(['name']);

        $excludedResponse = (new DataCollection(DefaultLazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/', 'GET', [
            'exclude' => 'name',
        ]));

        $this->assertSame([
            [],
            [],
            [],
        ], $excludedResponse->getData(true));
    }

    public function testCanDisableManuallyExcludingDataInTheRequestCollection(): void
    {
        DefaultLazyData::setAllowedExcludes([]);

        $response = (new DataCollection(DefaultLazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/', 'GET', [
            'exclude' => 'name',
        ]));

        $this->assertSame([
            ['name' => 'Ruben'],
            ['name' => 'Freek'],
            ['name' => 'Brent'],
        ], $response->getData(true));

        DefaultLazyData::setAllowedExcludes(['name']);

        $response = (new DataCollection(DefaultLazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/', 'GET', [
            'exclude' => 'name',
        ]));

        $this->assertSame([
            [],
            [],
            [],
        ], $response->getData(true));

        DefaultLazyData::setAllowedExcludes(null);

        $response = (new DataCollection(DefaultLazyData::class, ['Ruben', 'Freek', 'Brent']))->toResponse(Request::create('/', 'GET', [
            'exclude' => 'name',
        ]));

        $this->assertSame([
            [],
            [],
            [],
        ], $response->getData(true));
    }

    public function testCanWorkWithLazyArrayDataCollections(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(SimpleData::class)]
            public Lazy|array $lazyCollection;

            #[DataCollectionOf(NestedLazyData::class)]
            public Lazy|array $nestedLazyCollection;
        };

        $dataClass->lazyCollection = Lazy::create(fn (): array => [
            SimpleData::from('A'),
            SimpleData::from('B'),
        ]);

        $dataClass->nestedLazyCollection = Lazy::create(fn (): array => [
            NestedLazyData::from('C'),
            NestedLazyData::from('D'),
        ]);

        $this->assertSame([], $dataClass->toArray());

        $this->assertSame([
            'lazyCollection' => [
                ['string' => 'A'],
                ['string' => 'B'],
            ],
        ], $dataClass->include('lazyCollection')->toArray());

        $this->assertSame([
            'lazyCollection' => [
                ['string' => 'A'],
                ['string' => 'B'],
            ],

            'nestedLazyCollection' => [
                ['simple' => ['string' => 'C']],
                ['simple' => ['string' => 'D']],
            ],
        ], $dataClass->include('lazyCollection', 'nestedLazyCollection.simple')->toArray());
    }

    public function testCanWorkWithLazyHypervelDataCollections(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(SimpleData::class)]
            public Lazy|Collection $lazyCollection;

            #[DataCollectionOf(NestedLazyData::class)]
            public Lazy|Collection $nestedLazyCollection;
        };

        $dataClass->lazyCollection = Lazy::create(fn (): Collection => collect([
            SimpleData::from('A'),
            SimpleData::from('B'),
        ]));

        $dataClass->nestedLazyCollection = Lazy::create(fn (): Collection => collect([
            NestedLazyData::from('C'),
            NestedLazyData::from('D'),
        ]));

        $this->assertSame([], $dataClass->toArray());

        $this->assertSame([
            'lazyCollection' => [
                ['string' => 'A'],
                ['string' => 'B'],
            ],
        ], $dataClass->include('lazyCollection')->toArray());

        $this->assertSame([
            'lazyCollection' => [
                ['string' => 'A'],
                ['string' => 'B'],
            ],

            'nestedLazyCollection' => [
                ['simple' => ['string' => 'C']],
                ['simple' => ['string' => 'D']],
            ],
        ], $dataClass->include('lazyCollection', 'nestedLazyCollection.simple')->toArray());
    }

    public function testCanWorkWithLazyHypervelDataPaginators(): void
    {
        $dataClass = new class extends Data {
            #[DataCollectionOf(SimpleData::class)]
            public Lazy|Collection $lazyCollection;

            #[DataCollectionOf(NestedLazyData::class)]
            public Lazy|Collection $nestedLazyCollection;
        };

        $dataClass->lazyCollection = Lazy::create(fn (): LengthAwarePaginator => new LengthAwarePaginator([
            SimpleData::from('A'),
            SimpleData::from('B'),
        ], total: 15, perPage: 15));

        $dataClass->nestedLazyCollection = Lazy::create(fn (): LengthAwarePaginator => new LengthAwarePaginator([
            NestedLazyData::from('C'),
            NestedLazyData::from('D'),
        ], total: 15, perPage: 15));

        $this->assertSame([], $dataClass->toArray());

        $array = $dataClass->include('lazyCollection')->toArray();

        $this->assertSame([
            ['string' => 'A'],
            ['string' => 'B'],
        ], $array['lazyCollection']['data']);
        $this->assertArrayNotHasKey('nestedLazyCollection', $array);

        $array = $dataClass->include('lazyCollection', 'nestedLazyCollection.simple')->toArray();

        $this->assertSame([
            ['string' => 'A'],
            ['string' => 'B'],
        ], $array['lazyCollection']['data']);
        $this->assertSame([
            ['simple' => ['string' => 'C']],
            ['simple' => ['string' => 'D']],
        ], $array['nestedLazyCollection']['data']);
    }

    public function testPartialsAreAlwaysResetWhenTransformingAgain(): void
    {
        $dataClass = new class(Lazy::create(fn (): NestedLazyData => NestedLazyData::from('Hello World'))) extends Data {
            /**
             * Create a data object with a lazy nested object.
             */
            public function __construct(
                public Lazy|NestedLazyData $nested
            ) {
            }
        };

        $this->assertSame([
            'nested' => ['simple' => ['string' => 'Hello World']],
        ], $dataClass->include('nested.simple')->toArray());

        $this->assertSame([
            'nested' => [],
        ], $dataClass->include('nested')->toArray());

        $this->assertSame([], $dataClass->include()->toArray());
    }

    public function testCanDefinePermanentPartialsWhichWillAlwaysBeUsed(): void
    {
        $dataClass = new class(Lazy::create(fn (): NestedLazyData => NestedLazyData::from('Hello World')), Lazy::create(fn (): string => 'Hello World')) extends Data {
            /**
             * Create a data object with lazy nested and string values.
             */
            public function __construct(
                public Lazy|NestedLazyData $nested,
                public Lazy|string $string,
            ) {
            }

            /**
             * Get the permanently included properties.
             */
            protected function includeProperties(): array
            {
                return [
                    'nested.simple',
                ];
            }
        };

        $this->assertSame([
            'nested' => ['simple' => ['string' => 'Hello World']],
        ], $dataClass->toArray());

        $this->assertSame([
            'nested' => ['simple' => ['string' => 'Hello World']],
            'string' => 'Hello World',
        ], $dataClass->include('string')->toArray());

        $this->assertSame([
            'nested' => ['simple' => ['string' => 'Hello World']],
        ], $dataClass->toArray());
    }

    #[DataProvider('permanentPartialCases')]
    public function testCanDefinePermanentPartialsUsingFunctionCall(
        Data $data,
        Closure $temporaryPartial,
        Closure $permanentPartial,
        array $expectedFullPayload,
        array $expectedPartialPayload
    ): void {
        $data = $temporaryPartial($data);

        $this->assertSame($expectedPartialPayload, $data->toArray());
        $this->assertSame($expectedFullPayload, $data->toArray());

        $data = $permanentPartial($data);

        $this->assertSame($expectedPartialPayload, $data->toArray());
        $this->assertSame($expectedPartialPayload, $data->toArray());
    }

    /**
     * Provide each partial with its temporary and permanent forms and the payloads they produce.
     */
    public static function permanentPartialCases(): iterable
    {
        yield [
            new LazyData(
                Lazy::create(fn (): string => 'Rick Astley'),
            ), // data
            fn (LazyData $data): LazyData => $data->include('name'), // temporaryPartial
            fn (LazyData $data): LazyData => $data->includePermanently('name'), // permanentPartial
            [], // expectedFullPayload
            ['name' => 'Rick Astley'], // expectedPartialPayload
        ];

        yield [
            new LazyData(
                Lazy::create(fn (): string => 'Rick Astley')->defaultIncluded(),
            ), // data
            fn (LazyData $data): LazyData => $data->exclude('name'), // temporaryPartial
            fn (LazyData $data): LazyData => $data->excludePermanently('name'), // permanentPartial
            ['name' => 'Rick Astley'], // expectedFullPayload
            [], // expectedPartialPayload
        ];

        yield [
            new MultiData(
                'Rick Astley',
                'Never gonna give you up', // data
            ),
            fn (MultiData $data): MultiData => $data->only('first'), // temporaryPartial
            fn (MultiData $data): MultiData => $data->onlyPermanently('first'), // permanentPartial
            ['first' => 'Rick Astley', 'second' => 'Never gonna give you up'], // expectedFullPayload
            ['first' => 'Rick Astley'], // expectedPartialPayload
        ];

        yield [
            new MultiData(
                'Rick Astley',
                'Never gonna give you up', // data
            ),
            fn (MultiData $data): MultiData => $data->except('first'), // temporaryPartial
            fn (MultiData $data): MultiData => $data->exceptPermanently('first'), // permanentPartial
            ['first' => 'Rick Astley', 'second' => 'Never gonna give you up'], // expectedFullPayload
            ['second' => 'Never gonna give you up'], // expectedPartialPayload
        ];
    }

    public function testCanSetPartialsOnANestedDataObjectAndTheseWillBeRespected(): void
    {
        $collection = new DataCollection(TestMultiLazyNestedDataWithObjectAndCollection::class, [
            new TestMultiLazyNestedDataWithObjectAndCollection(
                NestedLazyData::from('A'),
                [
                    NestedLazyData::from('B1')->include('simple'),
                    NestedLazyData::from('B2'),
                ],
            ),
            new TestMultiLazyNestedDataWithObjectAndCollection(
                NestedLazyData::from('C'),
                [
                    NestedLazyData::from('D1'),
                    NestedLazyData::from('D2')->include('simple.string'),
                ],
            ),
        ]);

        $collection->include('nested.simple');

        $data = new class(Lazy::create(fn (): DataCollection => $collection)) extends Data {
            /**
             * Create a data object with a lazy data collection.
             */
            public function __construct(
                #[DataCollectionOf(TestMultiLazyNestedDataWithObjectAndCollection::class)]
                public Lazy|DataCollection $collection
            ) {
            }
        };

        $this->assertSame([
            'collection' => [
                [
                    'nested' => [
                        'simple' => [
                            'string' => 'A',
                        ],
                    ],
                    'nestedCollection' => [
                        [
                            'simple' => [
                                'string' => 'B1',
                            ],
                        ],
                        [],
                    ],
                ],
                [
                    'nested' => [
                        'simple' => [
                            'string' => 'C',
                        ],
                    ],
                    'nestedCollection' => [
                        [],
                        [
                            'simple' => [
                                'string' => 'D2',
                            ],
                        ],
                    ],
                ],
            ],
        ], $data->include('collection')->toArray());
    }

    /**
     * @param list<string> $expectedPartials the include paths the request resolves to
     */
    #[DataProvider('requestPartialCases')]
    public function testWillCheckIfPartialsAreValidAsRequestPartials(
        ?array $lazyDataAllowedIncludes,
        ?array $dataAllowedIncludes,
        ?string $includes,
        array $expectedPartials,
        array $expectedResponse
    ): void {
        LazyData::setAllowedIncludes($lazyDataAllowedIncludes);
        RequestPartialsCheckData::$allowedIncludes = $dataAllowedIncludes;

        $data = new RequestPartialsCheckData(
            Lazy::create(fn (): string => 'Hello'),
            Lazy::create(fn (): LazyData => LazyData::from('Hello')),
            Lazy::create(fn (): array => LazyData::collect(['Hello', 'World'])),
        );

        $request = Request::create('/', 'GET', $includes === null ? [] : ['include' => $includes]);

        // Hypervel applies the allowed paths to the transformation, rather than returning upstream's PartialsCollection.
        $context = $this->app->make(RequestQueryStringPartialsResolver::class)
            ->resolve($data, $request, TransformationContextFactory::create())
            ->get($data);

        $this->assertSame($expectedPartials, array_map(
            static fn (PartialDefinition $definition): string => $definition->path,
            $context->partialDefinitions['include'] ?? [],
        ));
        $this->assertSame($expectedResponse, $data->toResponse($request)->getData(true));
    }

    /**
     * Provide the allowed includes, the requested includes, and the resolved paths and response.
     */
    public static function requestPartialCases(): iterable
    {
        yield 'disallowed property inclusion' => [
            [], // lazyDataAllowedIncludes
            [], // dataAllowedIncludes
            'property', // includes
            [], // expectedPartials
            [], // expectedResponse
        ];

        yield 'allowed property inclusion' => [
            [], // lazyDataAllowedIncludes
            ['property'], // dataAllowedIncludes
            'property', // includes
            ['property'], // expectedPartials
            [
                'property' => 'Hello',
            ], // expectedResponse
        ];

        yield 'allowed data property inclusion without nesting' => [
            [], // lazyDataAllowedIncludes
            ['nested'], // dataAllowedIncludes
            'nested.name', // includes
            ['nested'], // expectedPartials
            [
                'nested' => [],
            ], // expectedResponse
        ];

        yield 'allowed data property inclusion with nesting' => [
            ['name'], // lazyDataAllowedIncludes
            ['nested'], // dataAllowedIncludes
            'nested.name', // includes
            ['nested.name'], // expectedPartials
            [
                'nested' => [
                    'name' => 'Hello',
                ],
            ], // expectedResponse
        ];

        yield 'allowed data collection property inclusion without nesting' => [
            [], // lazyDataAllowedIncludes
            ['collection'], // dataAllowedIncludes
            'collection.name', // includes
            ['collection'], // expectedPartials
            [
                'collection' => [
                    [],
                    [],
                ],
            ], // expectedResponse
        ];

        yield 'allowed data collection property inclusion with nesting' => [
            ['name'], // lazyDataAllowedIncludes
            ['collection'], // dataAllowedIncludes
            'collection.name', // includes
            ['collection.name'], // expectedPartials
            [
                'collection' => [
                    ['name' => 'Hello'],
                    ['name' => 'World'],
                ],
            ], // expectedResponse
        ];

        yield 'allowed nested data property inclusion without defining allowed includes on nested' => [
            null, // lazyDataAllowedIncludes
            ['nested'], // dataAllowedIncludes
            'nested.name', // includes
            ['nested.name'], // expectedPartials
            [
                'nested' => [
                    'name' => 'Hello',
                ],
            ], // expectedResponse
        ];

        yield 'allowed all nested data property inclusion without defining allowed includes on nested' => [
            null, // lazyDataAllowedIncludes
            ['nested'], // dataAllowedIncludes
            'nested.*', // includes
            ['nested.*'], // expectedPartials
            [
                'nested' => [
                    'name' => 'Hello',
                ],
            ], // expectedResponse
        ];

        yield 'disallowed all nested data property inclusion ' => [
            [], // lazyDataAllowedIncludes
            ['nested'], // dataAllowedIncludes
            'nested.*', // includes
            ['nested'], // expectedPartials
            [
                'nested' => [],
            ], // expectedResponse
        ];

        yield 'multi property inclusion' => [
            null, // lazyDataAllowedIncludes
            ['nested', 'property'], // dataAllowedIncludes
            'nested.*,property', // includes
            ['nested.*', 'property'], // expectedPartials
            [
                'property' => 'Hello',
                'nested' => [
                    'name' => 'Hello',
                ],
            ], // expectedResponse
        ];

        yield 'without property inclusion' => [
            null, // lazyDataAllowedIncludes
            ['nested', 'property'], // dataAllowedIncludes
            null, // includes
            [], // expectedPartials
            [], // expectedResponse
        ];

        yield 'with invalid partial definition' => [
            null, // lazyDataAllowedIncludes
            null, // dataAllowedIncludes
            '', // includes
            [], // expectedPartials
            [], // expectedResponse
        ];

        yield 'with non existing field' => [
            [], // lazyDataAllowedIncludes
            [], // dataAllowedIncludes
            'non-existing', // includes
            [], // expectedPartials
            [], // expectedResponse
        ];

        yield 'with non existing nested field' => [
            [], // lazyDataAllowedIncludes
            [], // dataAllowedIncludes
            'non-existing.still-non-existing', // includes
            [], // expectedPartials
            [], // expectedResponse
        ];

        yield 'with non allowed nested field' => [
            [], // lazyDataAllowedIncludes
            [], // dataAllowedIncludes
            'nested.name', // includes
            [], // expectedPartials
            [], // expectedResponse
        ];

        yield 'with non allowed nested all' => [
            [], // lazyDataAllowedIncludes
            [], // dataAllowedIncludes
            'nested.*', // includes
            [], // expectedPartials
            [], // expectedResponse
        ];
    }

    public function testCanCombineRequestAndManualIncludes(): void
    {
        $dataclass = new UnrestrictedMultiLazyData(
            Lazy::create(fn (): string => 'Rick Astley'),
            Lazy::create(fn (): string => 'Never gonna give you up'),
            Lazy::create(fn (): int => 1986),
        );

        $data = $dataclass->include('name')->toResponse(Request::create('/', 'GET', [
            'include' => 'artist',
        ]))->getData(true);

        $this->assertSame([
            'artist' => 'Rick Astley',
            'name' => 'Never gonna give you up',
        ], $data);
    }

    #[DataProvider('requestIncludeFormats')]
    public function testHandlesParsingIncludesFromRequestInDifferentFormats(array $input, array $expected): void
    {
        $dataclass = new WildcardMultiLazyData(
            Lazy::create(fn (): string => 'Rick Astley'),
            Lazy::create(fn (): string => 'Never gonna give you up'),
            Lazy::create(fn (): int => 1986),
        );

        $data = $dataclass->toResponse(Request::create('/', 'GET', $input))->getData(true);

        $this->assertSame($expected, array_keys($data));
    }

    /**
     * Provide the request include formats and the keys they select.
     */
    public static function requestIncludeFormats(): iterable
    {
        yield 'input as array' => [
            ['include' => ['artist', 'name']], // input
            ['artist', 'name'], // expected
        ];

        yield 'input as comma separated' => [
            ['include' => 'artist,name'], // input
            ['artist', 'name'], // expected
        ];
    }

    public function testHandlesPartialsWhenNotTransformingValuesByCopyingThemToLazyNestedDataObjects(): void
    {
        $dataClass = new class extends Data {
            public Lazy|NestedLazyData $nested;

            /**
             * Create a data object with a lazy nested object.
             */
            public function __construct()
            {
                $this->nested = Lazy::create(fn (): NestedLazyData => NestedLazyData::from('Rick Astley'));
            }
        };

        $this->assertSame([
            'nested' => [
                'simple' => [
                    'string' => 'Rick Astley',
                ],
            ],
        ], $dataClass->include('nested.simple')->toArray());

        $nested = $dataClass->include('nested.simple')->all()['nested'];

        $this->assertInstanceOf(NestedLazyData::class, $nested);
        $this->assertSame([
            'simple' => [
                'string' => 'Rick Astley',
            ],
        ], $nested->toArray());
    }

    public function testHandlesPartialsWhenNotTransformingValuesByCopyingThemToLazyDataCollections(): void
    {
        $dataClass = new class extends Data {
            public Lazy|DataCollection $collection;

            /**
             * Create a data object with a lazy data collection.
             */
            public function __construct()
            {
                $this->collection = Lazy::create(fn (): DataCollection => NestedLazyData::collect([
                    'Rick Astley',
                    'Jon Bon Jovi',
                ], DataCollection::class));
            }
        };

        $this->assertSame([
            'collection' => [
                [
                    'simple' => [
                        'string' => 'Rick Astley',
                    ],
                ],
                [
                    'simple' => [
                        'string' => 'Jon Bon Jovi',
                    ],
                ],
            ],
        ], $dataClass->include('collection.simple')->toArray());

        $nested = $dataClass->include('collection.simple')->all()['collection'];

        $this->assertInstanceOf(DataCollection::class, $nested);
        $this->assertSame([
            [
                'simple' => [
                    'string' => 'Rick Astley',
                ],
            ],
            [
                'simple' => [
                    'string' => 'Jon Bon Jovi',
                ],
            ],
        ], $nested->toArray());
    }

    public function testHandlesPartialsWhenNotTransformingValuesByCopyingThemToALazyArrayOfDataObjects(): void
    {
        $dataClass = new class extends Data {
            /** @var array<NestedLazyData> */
            public Lazy|array $collection;

            /**
             * Create a data object with a lazy array of data objects.
             */
            public function __construct()
            {
                $this->collection = Lazy::create(fn (): array => NestedLazyData::collect([
                    'Rick Astley',
                    'Jon Bon Jovi',
                ]));
            }
        };

        $this->assertSame([
            'collection' => [
                [
                    'simple' => [
                        'string' => 'Rick Astley',
                    ],
                ],
                [
                    'simple' => [
                        'string' => 'Jon Bon Jovi',
                    ],
                ],
            ],
        ], $dataClass->include('collection.simple')->toArray());

        $nested = $dataClass->include('collection.simple')->all()['collection'];

        $this->assertSame([
            [
                'simple' => [
                    'string' => 'Rick Astley',
                ],
            ],
            [
                'simple' => [
                    'string' => 'Jon Bon Jovi',
                ],
            ],
        ], array_map(fn (NestedLazyData $data): array => $data->toArray(), $nested));
    }

    public function testHandlesPartialsWhenNotTransformingValuesByCopyingThemToLazyNestedDataObjectsInDataCollections(): void
    {
        $dataClass = new class extends Data {
            public DataCollection $collection;

            /**
             * Create a data object with a data collection.
             */
            public function __construct()
            {
                $this->collection = NestedLazyData::collect([
                    'Rick Astley',
                    'Jon Bon Jovi',
                ], DataCollection::class);
            }
        };

        $this->assertSame([
            'collection' => [
                [
                    'simple' => [
                        'string' => 'Rick Astley',
                    ],
                ],
                [
                    'simple' => [
                        'string' => 'Jon Bon Jovi',
                    ],
                ],
            ],
        ], $dataClass->include('collection.simple')->toArray());

        $nested = $dataClass->include('collection.simple')->all()['collection'];

        $this->assertInstanceOf(DataCollection::class, $nested);
        $this->assertSame([
            [
                'simple' => [
                    'string' => 'Rick Astley',
                ],
            ],
            [
                'simple' => [
                    'string' => 'Jon Bon Jovi',
                ],
            ],
        ], $nested->toArray());
    }

    public function testHandlesParsingExceptFromRequestWithMappedOutputName(): void
    {
        $dataclass = SimpleDataWithMappedOutputName::from([
            'id' => 1,
            'amount' => 1000,
            'any_string' => 'test',
            'child' => SimpleChildDataWithMappedOutputName::from([
                'id' => 2,
                'amount' => 2000,
            ]),
        ]);

        $request = Request::create('/', 'GET', ['except' => ['paid_amount', 'any_string', 'child.child_amount']]);

        $data = $dataclass->toResponse($request)->getData(true);

        $this->assertSame([
            'id' => 1,
            'child' => [
                'id' => 2,
            ],
        ], $data);
    }

    public function testHandlesCircularDependencies(): void
    {
        $dataClass = new CircData(
            'test',
            new UlarData(
                'test',
                new CircData('test', null)
            )
        );

        $data = $dataClass->toResponse(Request::create('/'))->getData(true);

        $this->assertSame([
            'string' => 'test',
            'ular' => [
                'string' => 'test',
                'circ' => [
                    'string' => 'test',
                    'ular' => null,
                ],
            ],
        ], $data);
        // Not really a test with expectation, we just want to check we don't end up in an infinite loop
    }
}

class TestMultiLazyNestedDataWithObjectAndCollection extends Data
{
    /**
     * Create a fixture with a lazy nested object and a lazy nested array.
     */
    public function __construct(
        public Lazy|NestedLazyData $nested,
        #[DataCollectionOf(NestedLazyData::class)]
        public Lazy|array $nestedCollection,
    ) {
    }
}

class RequestPartialsCheckData extends Data
{
    public static ?array $allowedIncludes = null;

    /**
     * Create a fixture with a lazy property, nested object and collection.
     */
    public function __construct(
        public Lazy|string $property,
        public Lazy|LazyData $nested,
        #[DataCollectionOf(LazyData::class)]
        public Lazy|array $collection,
    ) {
    }

    /**
     * Get the includes a request may ask for.
     */
    public static function allowedRequestIncludes(): ?array
    {
        return static::$allowedIncludes;
    }
}

class UnrestrictedMultiLazyData extends MultiLazyData
{
    /**
     * Allow every request include.
     */
    public static function allowedRequestIncludes(): ?array
    {
        return null;
    }
}

class WildcardMultiLazyData extends MultiLazyData
{
    /**
     * Allow every request include through a wildcard.
     */
    public static function allowedRequestIncludes(): ?array
    {
        return ['*'];
    }
}

#[MapName(SnakeCaseMapper::class)]
class SimpleDataWithMappedOutputName extends Data
{
    /**
     * Create a fixture with mapped output names.
     */
    public function __construct(
        public int $id,
        #[MapOutputName('paid_amount')]
        public float $amount,
        public string $anyString,
        public SimpleChildDataWithMappedOutputName $child
    ) {
    }

    /**
     * Get the except selections a request may ask for.
     */
    public static function allowedRequestExcept(): ?array
    {
        return [
            'amount',
            'anyString',
            'child',
        ];
    }
}
