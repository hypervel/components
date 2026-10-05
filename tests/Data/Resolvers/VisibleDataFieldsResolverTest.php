<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Resolvers\VisibleDataFieldsResolverTest;

use Closure;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Exceptions\CannotPerformPartialOnDataField;
use Hypervel\Data\Lazy;
use Hypervel\Data\Optional;
use Hypervel\Data\Support\Lazy\ClosureLazy;
use Hypervel\Data\Support\Lazy\InertiaDeferred;
use Hypervel\Data\Support\Lazy\InertiaLazy;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Inertia\DeferProp;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\OptionalProp;
use Hypervel\Testbench\Attributes\WithConfig;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\FakeModelData;
use Hypervel\Tests\Data\Fixtures\FakeNestedModelData;
use Hypervel\Tests\Data\Fixtures\Models\FakeNestedModel;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use PHPUnit\Framework\Attributes\DataProvider;

class VisibleDataFieldsResolverTest extends TestCase
{
    use RefreshDatabase;

    // Spatie's VisibleDataFieldsResolver class is not included; the transformer selects visible fields while
    // transforming, so each case asserts the transformed output, which upstream also checks, instead of the
    // resolver's field contexts. Upstream's default-instance resolver checks become default-instance transformations.

    protected bool $migrateRefresh = true;

    /**
     * Get package providers for the test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Get the migration options.
     */
    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/../Fixtures/Migrations',
        ];
    }

    public function testWillHideHiddenFields(): void
    {
        $dataClass = new class extends Data {
            public string $visible = 'visible';

            #[Hidden]
            public string $hidden = 'hidden';
        };

        $this->assertSame([
            'visible' => 'visible',
        ], $dataClass->toArray());
    }

    public function testWillHideFieldsWhichAreUninitialized(): void
    {
        $dataClass = new class extends Data {
            public string $visible = 'visible';

            public Optional|string $optional;
        };

        $this->assertSame([
            'visible' => 'visible',
        ], $dataClass->toArray());
    }

    public function testWillHideOptionalValues(): void
    {
        $dataClass = new class extends Data {
            public Lazy|string|Optional $lazyOptional;

            /**
             * Create a data object with optional values.
             */
            public function __construct(
                public string $visible = 'visible',
                public Optional|string $optional = new Optional,
            ) {
                $this->lazyOptional = Lazy::create(fn (): Optional => new Optional);
            }
        };

        $this->assertSame([
            'visible' => 'visible',
        ], $dataClass->toArray());

        $this->assertSame([
            'visible' => 'visible',
        ], $dataClass->include('lazyOptional')->toArray());
    }

    public function testWillAlwaysShowNonLazyValuesWhenNoOnlyOrExcludeOperationsArePerformedOnIt(): void
    {
        $dataClass = new class extends Data {
            /**
             * Create a data object with a visible lazy-capable value.
             */
            public function __construct(
                public string $visible = 'visible',
                public Lazy|string $lazy = 'lazy but visible',
            ) {
            }
        };

        $this->assertSame([
            'visible' => 'visible',
            'lazy' => 'lazy but visible',
        ], $dataClass->toArray());
    }

    public function testCanHaveLazyBehaviourBasedUponACondition(): void
    {
        $dataClass = new class extends Data {
            /**
             * Create a data object with an optional name.
             */
            public function __construct(
                public string|Lazy|null $name = null
            ) {
            }

            /**
             * Create a data object whose name is included only for Ruben.
             */
            public static function create(string $name): static
            {
                return new self(
                    Lazy::when(fn (): bool => $name === 'Ruben', fn (): string => $name)
                );
            }
        };

        $this->assertSame(['name' => null], $dataClass->toArray());
        $this->assertSame([], $dataClass::create('Freek')->toArray());
        $this->assertSame(['name' => 'Ruben'], $dataClass::create('Ruben')->toArray());
    }

    public function testIsImpossibleToLazyIncludeConditionalLazyProperties(): void
    {
        $dataClass = new class extends Data {
            /**
             * Create a data object with an optional name.
             */
            public function __construct(
                public string|Lazy|null $name = null
            ) {
            }

            /**
             * Create a data object whose name is included only for Ruben.
             */
            public static function create(string $name): static
            {
                return new self(
                    Lazy::when(fn (): bool => $name === 'Ruben', fn (): string => $name)
                );
            }
        };

        $this->assertSame([], $dataClass::create('Freek')->include('name')->toArray());
    }

    public function testCanIncludeDataBasedUponRelationsBeingLoaded(): void
    {
        $model = FakeNestedModel::factory()->create();

        $transformed = FakeNestedModelData::createWithLazyWhenLoaded($model)->all();

        $this->assertArrayNotHasKey('fake_model', $transformed);

        $transformed = FakeNestedModelData::createWithLazyWhenLoaded($model->load('fakeModel'))->all();

        $this->assertArrayHasKey('fake_model', $transformed);
        $this->assertInstanceOf(FakeModelData::class, $transformed['fake_model']);
    }

    public function testCanIncludeDataBasedUponRelationsLoadedWhenTheyAreNull(): void
    {
        $model = FakeNestedModel::factory(['fake_model_id' => null])->create();

        $transformed = FakeNestedModelData::createWithLazyWhenLoaded($model)->all();

        $this->assertArrayNotHasKey('fake_model', $transformed);

        $transformed = FakeNestedModelData::createWithLazyWhenLoaded($model->load('fakeModel'))->all();

        $this->assertArrayHasKey('fake_model', $transformed);
        $this->assertNull($transformed['fake_model']);
    }

    public function testCanIncludeLazyDataByDefault(): void
    {
        $dataClass = new class('') extends Data {
            /**
             * Create a data object with a name.
             */
            public function __construct(
                public string|Lazy $name
            ) {
            }

            /**
             * Create a data object whose lazy name is included by default.
             */
            public static function create(string $name): static
            {
                return new self(
                    Lazy::create(fn (): string => $name)->defaultIncluded()
                );
            }
        };

        $this->assertSame(['name' => 'Ruben'], $dataClass::create('Ruben')->toArray());
    }

    public function testAlwaysTransformsLazyInertiaDataToInertiaLazyProps(): void
    {
        // Upstream skips this case until Inertia supports Laravel 12; Hypervel's Inertia adapter is available.
        $dataClass = new class extends Data {
            /**
             * Create a data object with an optional name.
             */
            public function __construct(
                public string|InertiaLazy|null $name = null
            ) {
            }

            /**
             * Create a data object with an Inertia lazy name.
             */
            public static function create(string $name): static
            {
                return new self(
                    Lazy::inertia(fn (): string => $name)
                );
            }
        };

        $this->assertInstanceOf(OptionalProp::class, $dataClass::create('Freek')->toArray()['name']);
    }

    public function testAlwaysTransformsDeferredInertiaDataToInertiaDeferredProps(): void
    {
        $dataClass = new class extends Data {
            /**
             * Create a data object with an optional name.
             */
            public function __construct(
                public string|InertiaDeferred|null $name = null
            ) {
            }

            /**
             * Create a data object with an Inertia deferred name.
             */
            public static function create(string $name): static
            {
                return new self(
                    Lazy::inertiaDeferred(Inertia::defer(fn (): string => $name))
                );
            }
        };

        $this->assertInstanceOf(DeferProp::class, $dataClass::create('Freek')->toArray()['name']);
    }

    public function testAlwaysTransformsClosureLazyIntoClosuresForInertia(): void
    {
        // Upstream skips this case until Inertia supports Laravel 12; it needs no Inertia support here.
        $dataClass = new class extends Data {
            /**
             * Create a data object with an optional name.
             */
            public function __construct(
                public string|ClosureLazy|null $name = null
            ) {
            }

            /**
             * Create a data object with a closure lazy name.
             */
            public static function create(string $name): static
            {
                return new self(
                    Lazy::closure(fn (): string => $name)
                );
            }
        };

        $this->assertInstanceOf(Closure::class, $dataClass::create('Freek')->toArray()['name']);
    }

    #[DataProvider('partialOperationProvider')]
    public function testWillFailGracefullyWhenANestedFieldDoesNotExist(string $operation): void
    {
        $this->expectException(CannotPerformPartialOnDataField::class);
        $this->expectExceptionMessage(
            "Cannot apply the [{$operation}] partial to unknown data property [certainly-not-simple]",
        );

        (new MissingNestedFieldData)->{$operation}('certainly-not-simple.string')->toArray();
    }

    /**
     * Provide the partial operations that check nested paths.
     *
     * @return array<string, array{string}>
     */
    public static function partialOperationProvider(): array
    {
        return [
            'include' => ['include'],
            'exclude' => ['exclude'],
            'only' => ['only'],
            'except' => ['except'],
        ];
    }

    #[WithConfig('data.ignore_invalid_partials', true)]
    public function testWillIgnoreANestedFieldThatDoesNotExistWhenConfigured(): void
    {
        // Upstream's second half; the option is read once at boot, so it is set for the whole test.
        $this->assertSame([
            'string' => 'World',
        ], (new MissingNestedFieldData)->include('certainly-not-simple.string', 'string')->toArray());
    }

    /**
     * @param Closure(): TransformationContextFactory $factory
     */
    #[DataProvider('exceptsProvider')]
    public function testCanExecuteExcepts(Closure $factory, array $expectedTransformed): void
    {
        $this->assertSame($expectedTransformed, VisibleFieldsData::instance()->transform($factory()));
    }

    /**
     * Provide except selections and their transformed output.
     */
    public static function exceptsProvider(): iterable
    {
        yield 'single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('single'),
            [
                'string' => 'hello',
                'int' => 42,
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('{string,int,single}'),
            [
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('*'),
            [],
        ];

        yield 'nested data object single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('string', 'int', 'single', 'collection') // ignore non nested object fields
                ->except('nested.a'),
            [
                'nested' => [
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data object multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('string', 'int', 'single', 'collection') // ignore non nested object fields
                ->except('nested.{a,b}'),
            [
                'nested' => [],
            ],
        ];

        yield 'nested data object all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('string', 'int', 'single', 'collection') // ignore non nested object fields
                ->except('nested.*'),
            [
                'nested' => [],
            ],
        ];

        yield 'nested data collectable single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('string', 'int', 'single', 'nested') // ignore non collection fields
                ->except('collection.string'),
            [
                'collection' => [
                    ['int' => 42],
                    ['int' => 42],
                ],
            ],
        ];

        yield 'nested data collectable multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('string', 'int', 'single', 'nested') // ignore non collection fields
                ->except('collection.{string,int}'),
            [
                'collection' => [
                    [],
                    [],
                ],
            ],
        ];

        yield 'nested data collectable all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('string', 'int', 'single', 'nested') // ignore non collection fields
                ->except('collection.*'),
            [
                'collection' => [
                    [],
                    [],
                ],
            ],
        ];

        yield 'combination' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->except('string', 'int', 'single.string')
                ->except('collection.string')
                ->except('nested.a.string'),
            [
                'single' => ['int' => 42],
                'nested' => [
                    'a' => ['int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
                'collection' => [
                    ['int' => 42],
                    ['int' => 42],
                ],
            ],
        ];
    }

    /**
     * @param Closure(): TransformationContextFactory $factory
     */
    #[DataProvider('onlysProvider')]
    public function testCanExecuteOnlys(Closure $factory, array $expectedTransformed): void
    {
        $this->assertSame($expectedTransformed, VisibleFieldsData::instance()->transform($factory()));
    }

    /**
     * Provide only selections and their transformed output.
     */
    public static function onlysProvider(): iterable
    {
        yield 'single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('single'),
            [
                'single' => ['string' => 'hello', 'int' => 42],
            ],
        ];

        yield 'multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('{string,int,single}'),
            [
                'string' => 'hello',
                'int' => 42,
                'single' => ['string' => 'hello', 'int' => 42],
            ],
        ];

        yield 'all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('*'),
            [
                'string' => 'hello',
                'int' => 42,
                'single' => ['string' => 'hello', 'int' => 42],
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data object single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested.a'),
            [
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data object multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested.{a,b}'),
            [
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data object all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested.*'),
            [
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data collectable single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('collection.string'),
            [
                'collection' => [
                    ['string' => 'hello'],
                    ['string' => 'hello'],
                ],
            ],
        ];

        yield 'nested data collectable multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('collection.{string,int}'),
            [
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data collectable all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('collection.*'),
            [
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'combination' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('string', 'single.string')
                ->only('collection.string')
                ->only('nested.a.string'),
            [
                'string' => 'hello',
                'single' => ['string' => 'hello'],
                'nested' => [
                    'a' => ['string' => 'hello'],
                ],
                'collection' => [
                    ['string' => 'hello'],
                    ['string' => 'hello'],
                ],
            ],
        ];
    }

    /**
     * @param Closure(): TransformationContextFactory $factory
     */
    #[DataProvider('includesProvider')]
    public function testCanExecuteIncludes(Closure $factory, array $expectedTransformed): void
    {
        $this->assertSame($expectedTransformed, LazyVisibleFieldsData::instance(false)->transform($factory()));
    }

    /**
     * Provide include selections and their transformed output.
     */
    public static function includesProvider(): iterable
    {
        yield 'single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->include('single'),
            [
                'single' => [],
            ],
        ];

        yield 'multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->include('{string,int,single}'),
            [
                'string' => 'hello',
                'int' => 42,
                'single' => [],
            ],
        ];

        yield 'all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->include('*'),
            [
                'string' => 'hello',
                'int' => 42,
                'single' => ['string' => 'hello', 'int' => 42],
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data object single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested') // ignore non nested object fields
                ->include('nested.a'),
            [
                'nested' => [
                    'a' => [],
                ],
            ],
        ];

        yield 'nested data object multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested') // ignore non nested object fields
                ->include('nested.{a,b}'),
            [
                'nested' => [
                    'a' => [],
                    'b' => [],
                ],
            ],
        ];

        yield 'nested data object all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested') // ignore non nested object fields
                ->include('nested.*'),
            [
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data object deep nesting' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested') // ignore non nested object fields
                ->include('nested.a.string', 'nested.b.int'),
            [
                'nested' => [
                    'a' => ['string' => 'hello'],
                    'b' => ['int' => 42],
                ],
            ],
        ];

        yield 'nested data collectable single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('collection') // ignore non collection fields
                ->include('collection.string'),
            [
                'collection' => [
                    ['string' => 'hello'],
                    ['string' => 'hello'],
                ],
            ],
        ];

        yield 'nested data collectable multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('collection') // ignore non collection fields
                ->include('collection.{string,int}'),
            [
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data collectable all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('collection') // ignore non collection fields
                ->include('collection.*'),
            [
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'combination' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->include('string', 'single.string')
                ->include('collection.string')
                ->include('nested.a.string'),
            [
                'string' => 'hello',
                'single' => ['string' => 'hello'],
                'nested' => [
                    'a' => ['string' => 'hello'],
                ],
                'collection' => [
                    ['string' => 'hello'],
                    ['string' => 'hello'],
                ],
            ],
        ];
    }

    /**
     * @param Closure(): TransformationContextFactory $factory
     */
    #[DataProvider('excludesProvider')]
    public function testCanExecuteExcludes(Closure $factory, array $expectedTransformed): void
    {
        $this->assertSame($expectedTransformed, LazyVisibleFieldsData::instance(true)->transform($factory()));
    }

    /**
     * Provide exclude selections and their transformed output.
     */
    public static function excludesProvider(): iterable
    {
        yield 'single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->exclude('single'),
            [
                'string' => 'hello',
                'int' => 42,
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->exclude('{string,int,single}'),
            [
                'nested' => [
                    'a' => ['string' => 'hello', 'int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
                'collection' => [
                    ['string' => 'hello', 'int' => 42],
                    ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->exclude('*'),
            [],
        ];

        yield 'nested data object single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested') // ignore non nested object fields
                ->exclude('nested.a'),
            [
                'nested' => [
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
            ],
        ];

        yield 'nested data object multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested') // ignore non nested object fields
                ->exclude('nested.{a,b}'),
            [
                'nested' => [],
            ],
        ];

        yield 'nested data object all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested') // ignore non nested object fields
                ->exclude('nested.*'),
            [
                'nested' => [],
            ],
        ];

        yield 'nested data object deep nesting' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('nested') // ignore non nested object fields
                ->exclude('nested.a.string', 'nested.b.int'),
            [
                'nested' => [
                    'a' => ['int' => 42],
                    'b' => ['string' => 'hello'],
                ],
            ],
        ];

        yield 'nested data collectable single field' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('collection') // ignore non collection fields
                ->exclude('collection.string'),
            [
                'collection' => [
                    ['int' => 42],
                    ['int' => 42],
                ],
            ],
        ];

        yield 'nested data collectable multiple fields' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('collection') // ignore non collection fields
                ->exclude('collection.{string,int}'),
            [
                'collection' => [
                    [],
                    [],
                ],
            ],
        ];

        yield 'nested data collectable all' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->only('collection') // ignore non collection fields
                ->exclude('collection.*'),
            [
                'collection' => [
                    [],
                    [],
                ],
            ],
        ];

        yield 'combination' => [
            fn (): TransformationContextFactory => TransformationContextFactory::create()
                ->exclude('string', 'single.string')
                ->exclude('collection.string')
                ->exclude('nested.a.string'),
            [
                'int' => 42,
                'single' => ['int' => 42],
                'nested' => [
                    'a' => ['int' => 42],
                    'b' => ['string' => 'hello', 'int' => 42],
                ],
                'collection' => [
                    ['int' => 42],
                    ['int' => 42],
                ],
            ],
        ];
    }

    public function testCanCombineAllThePartials(): void
    {
        $data = new LazyVisibleFieldsData(
            Lazy::create(fn (): string => 'hello'),
            Lazy::create(fn (): int => 42),
            Lazy::create(fn (): LazyVisibleFieldsSingleData => LazyVisibleFieldsSingleData::instance(true))->defaultIncluded(),
            Lazy::create(fn (): LazyVisibleFieldsNestedData => LazyVisibleFieldsNestedData::instance(false)),
            Lazy::create(fn (): array => [
                LazyVisibleFieldsSingleData::instance(false),
                LazyVisibleFieldsSingleData::instance(true),
            ]),
        );

        $factory = TransformationContextFactory::create()
            ->except('int', 'collection.int', 'nested.b.int')
            ->only('single.*', 'nested.*', 'collection.*', 'string')
            ->include('nested.a.string', 'nested.b.*', 'collection.string')
            ->exclude('single.int');

        $this->assertSame([
            'single' => ['string' => 'hello'],
            'nested' => [
                'a' => ['string' => 'hello'],
                'b' => ['string' => 'hello'],
            ],
            'collection' => [
                ['string' => 'hello'],
                ['string' => 'hello'],
            ],
        ], $data->transform($factory));
    }

    // REMOVED: 'can handle custom transformation contexts'; TransformationContext is a final immutable value and the
    // transformation engine is fixed, so there are no replaceable resolvers to receive a custom context subclass.
}

class MissingNestedFieldData extends Data
{
    public Lazy|SimpleData $simple;

    public Lazy|string $string;

    /**
     * Create a data object with lazy nested and string values.
     */
    public function __construct()
    {
        $this->simple = Lazy::create(fn (): SimpleData => new SimpleData('Hello'));
        $this->string = Lazy::create(fn (): string => 'World');
    }
}

class VisibleFieldsSingleData extends Data
{
    /**
     * Create a data object with a string and an integer.
     */
    public function __construct(
        public string $string,
        public int $int
    ) {
    }

    /**
     * Create the standard instance.
     */
    public static function instance(): self
    {
        return new self('hello', 42);
    }
}

class VisibleFieldsNestedData extends Data
{
    /**
     * Create a data object with two nested objects.
     */
    public function __construct(
        public VisibleFieldsSingleData $a,
        public VisibleFieldsSingleData $b,
    ) {
    }

    /**
     * Create the standard instance.
     */
    public static function instance(): self
    {
        return new self(
            VisibleFieldsSingleData::instance(),
            VisibleFieldsSingleData::instance(),
        );
    }
}

class VisibleFieldsData extends Data
{
    /**
     * Create a data object with scalar, nested and collection values.
     */
    public function __construct(
        public string $string,
        public int $int,
        public VisibleFieldsSingleData $single,
        public VisibleFieldsNestedData $nested,
        #[DataCollectionOf(VisibleFieldsSingleData::class)]
        public array $collection,
    ) {
    }

    /**
     * Create the standard instance.
     */
    public static function instance(): self
    {
        return new self(
            'hello',
            42,
            VisibleFieldsSingleData::instance(),
            VisibleFieldsNestedData::instance(),
            [
                VisibleFieldsSingleData::instance(),
                VisibleFieldsSingleData::instance(),
            ],
        );
    }
}

class LazyVisibleFieldsSingleData extends Data
{
    /**
     * Create a data object with lazy string and integer values.
     */
    public function __construct(
        public Lazy|string $string,
        public Lazy|int $int
    ) {
    }

    /**
     * Create the standard instance, with its lazy values optionally included by default.
     */
    public static function instance(bool $includeByDefault): self
    {
        return new self(
            Lazy::create(fn (): string => 'hello')->defaultIncluded($includeByDefault),
            Lazy::create(fn (): int => 42)->defaultIncluded($includeByDefault)
        );
    }
}

class LazyVisibleFieldsNestedData extends Data
{
    /**
     * Create a data object with two lazy nested objects.
     */
    public function __construct(
        public Lazy|LazyVisibleFieldsSingleData $a,
        public Lazy|LazyVisibleFieldsSingleData $b,
    ) {
    }

    /**
     * Create the standard instance, with its lazy values optionally included by default.
     */
    public static function instance(bool $includeByDefault): self
    {
        return new self(
            Lazy::create(fn (): LazyVisibleFieldsSingleData => LazyVisibleFieldsSingleData::instance($includeByDefault))->defaultIncluded($includeByDefault),
            Lazy::create(fn (): LazyVisibleFieldsSingleData => LazyVisibleFieldsSingleData::instance($includeByDefault))->defaultIncluded($includeByDefault),
        );
    }
}

class LazyVisibleFieldsData extends Data
{
    /**
     * Create a data object with lazy scalar, nested and collection values.
     */
    public function __construct(
        public Lazy|string $string,
        public Lazy|int $int,
        public Lazy|LazyVisibleFieldsSingleData $single,
        public Lazy|LazyVisibleFieldsNestedData $nested,
        #[DataCollectionOf(LazyVisibleFieldsSingleData::class)]
        public Lazy|array $collection,
    ) {
    }

    /**
     * Create the standard instance, with its lazy values optionally included by default.
     */
    public static function instance(bool $includeByDefault): self
    {
        return new self(
            Lazy::create(fn (): string => 'hello')->defaultIncluded($includeByDefault),
            Lazy::create(fn (): int => 42)->defaultIncluded($includeByDefault),
            Lazy::create(fn (): LazyVisibleFieldsSingleData => LazyVisibleFieldsSingleData::instance($includeByDefault))->defaultIncluded($includeByDefault),
            Lazy::create(fn (): LazyVisibleFieldsNestedData => LazyVisibleFieldsNestedData::instance($includeByDefault))->defaultIncluded($includeByDefault),
            Lazy::create(fn (): array => [
                LazyVisibleFieldsSingleData::instance($includeByDefault),
                LazyVisibleFieldsSingleData::instance($includeByDefault),
            ])->defaultIncluded($includeByDefault),
        );
    }
}
