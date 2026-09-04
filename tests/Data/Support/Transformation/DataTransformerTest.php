<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Transformation\DataTransformerTest;

use AllowDynamicProperties;
use ArrayIterator;
use BackedEnum;
use Closure;
use DateTimeImmutable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\AutoInertiaDeferred;
use Hypervel\Data\Attributes\AutoInertiaLazy;
use Hypervel\Data\Attributes\AutoLazy;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Attributes\WithTransformer;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\BaseDataCollectable;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Exceptions\CannotTransformData;
use Hypervel\Data\Lazy;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\DataTransformer;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Data\Transformers\Transformer;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Inertia\DeferProp;
use Hypervel\Inertia\OptionalProp;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\LengthAwarePaginator;
use Hypervel\Testbench\TestCase;
use RuntimeException;
use Traversable;

class DataTransformerTest extends TestCase
{
    // REMOVED: SerializeTransformer tests; native PHP serialization owns object serialization.

    /**
     * Get package providers for the transformation test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Test ordinary values transform through the fixed metadata path.
     */
    public function testTransformsLiveMappedNestedAndBuiltInValues(): void
    {
        $date = new DateTimeImmutable('2026-08-31T10:30:00+00:00');
        $nested = new SimpleData('nested');
        $data = new TransformingData('first', $date, Status::Ready, $nested);

        $this->assertSame([
            'display_name' => 'first',
            'createdAt' => '2026-08-31T10:30:00+00:00',
            'status' => 'ready',
            'nested' => ['value' => 'nested'],
        ], $data->toArray());

        $data->name = 'changed';

        $this->assertSame('changed', $data->toArray()['display_name']);
        $this->assertSame([
            'display_name' => 'changed',
            'createdAt' => $date,
            'status' => Status::Ready,
            'nested' => $nested,
        ], $data->all());
    }

    /**
     * Test fixed output recipes match the general transformation path.
     */
    public function testFixedOutputRecipeMatchesGeneralTransformation(): void
    {
        $date = new DateTimeImmutable('2026-08-31T10:30:00+00:00');
        $data = new RecipeOutputData(
            'Taylor',
            $date,
            Status::Ready,
            new SimpleData('nested'),
        );
        $irrelevantTransformer = new class implements Transformer {
            public function transform(
                DataProperty $property,
                mixed $value,
                TransformationContext $context,
            ): mixed {
                return $value;
            }
        };
        $general = TransformationContextFactory::create()
            ->withTransformer(RuntimeException::class, $irrelevantTransformer);

        $this->assertSame([
            'computed' => 'computed',
            'display_name' => 'Taylor',
            'createdAt' => '2026-08-31T10:30:00+00:00',
            'status' => 'ready',
            'nested' => ['value' => 'nested'],
        ], $data->toArray());
        $this->assertSame($data->toArray(), $data->transform($general));

        $unmapped = TransformationContextFactory::create()->withoutPropertyNameMapping();
        $generalUnmapped = TransformationContextFactory::create()
            ->withoutPropertyNameMapping()
            ->withTransformer(RuntimeException::class, $irrelevantTransformer);

        $this->assertSame($data->transform($generalUnmapped), $data->transform($unmapped));
        $this->assertArrayHasKey('name', $data->transform($unmapped));
        $this->assertArrayNotHasKey('display_name', $data->transform($unmapped));
    }

    /**
     * Test plain values retain bulk-copy transformation without value conversion.
     */
    public function testAllUsesBulkCopyForPlainData(): void
    {
        $transformer = $this->app->make(BulkCopyRecordingDataTransformer::class);
        $this->app->instance(DataTransformer::class, $transformer);
        $data = new ArrayData(['nested' => ['value' => 'value']]);
        $transformed = $data->toArray();

        $this->assertSame(1, $transformer->bulkCopyCalls);
        $this->assertSame($transformed, $data->all());
        $this->assertSame(2, $transformer->bulkCopyCalls);
    }

    /**
     * Test property transformers prevent bulk-copy transformation.
     */
    public function testPropertyTransformersPreventBulkCopy(): void
    {
        $transformer = $this->app->make(BulkCopyRecordingDataTransformer::class);
        $this->app->instance(DataTransformer::class, $transformer);

        $this->assertSame(
            ['value' => 'TRANSFORMED'],
            (new PropertyTransformedData('transformed'))->toArray(),
        );
        $this->assertSame(0, $transformer->bulkCopyCalls);
    }

    /**
     * Test data-annotated arrays prevent bulk-copy transformation.
     */
    public function testDataAnnotatedArraysPreventBulkCopy(): void
    {
        $transformer = $this->app->make(BulkCopyRecordingDataTransformer::class);
        $this->app->instance(DataTransformer::class, $transformer);

        $this->assertSame(
            ['items' => [['value' => 'nested']]],
            (new AnnotatedArrayOwnerData([new SimpleData('nested')]))->toArray(),
        );
        $this->assertNotContains(AnnotatedArrayOwnerData::class, $transformer->bulkCopiedClasses);
    }

    /**
     * Test nested paginator properties retain native metadata.
     */
    public function testTransformsNestedOffsetAndCursorPaginatorsWithMetadata(): void
    {
        $offsetItem = new PaginatorItemData(1, 'offset');
        $cursorItem = new PaginatorItemData(2, 'cursor');
        $offset = new LengthAwarePaginator(
            [$offsetItem],
            12,
            5,
            2,
            ['path' => '/offset', 'query' => ['tenant' => 'one']],
        );
        $cursor = new CursorPaginator(
            [$cursorItem, new PaginatorItemData(3, 'next')],
            1,
            options: [
                'path' => '/cursor',
                'query' => ['tenant' => 'one'],
                'parameters' => ['id'],
            ],
        );

        $transformed = (new PaginatorOwnerData($offset, $cursor))->toArray();

        $this->assertSame([
            'id' => 1,
            'label_text' => 'offset',
        ], $transformed['offset']['data'][0]);
        $this->assertSame(2, $transformed['offset']['current_page']);
        $this->assertSame(12, $transformed['offset']['total']);
        $this->assertSame('/offset', $transformed['offset']['path']);
        $this->assertSame([
            'id' => 2,
            'label_text' => 'cursor',
        ], $transformed['cursor']['data'][0]);
        $this->assertSame('/cursor', $transformed['cursor']['path']);
        $this->assertNotNull($transformed['cursor']['next_cursor']);
        $this->assertStringContainsString('tenant=one', $transformed['cursor']['next_page_url']);
        $this->assertSame($offsetItem, $offset->items()[0]);
        $this->assertSame($cursorItem, $cursor->items()[0]);
    }

    /**
     * Test plain transformation follows metadata order and declared properties.
     */
    public function testPlainTransformPreservesMetadataOrderAndFiltersRuntimeKeys(): void
    {
        $data = new PlainOrderData;
        $data->runtime = 'ignored';

        $this->assertSame([
            'child' => 'child',
            'redeclared' => 'child-redeclared',
            'parent' => 'parent',
        ], $data->toArray());
    }

    /**
     * Test plain transformation reads public property hooks exactly once.
     */
    public function testPlainTransformReadsBackedAndVirtualHooksOnce(): void
    {
        PlainHookData::$backedReads = 0;
        PlainHookData::$virtualReads = 0;

        $this->assertSame([
            'backed' => 'BACKED',
            'virtual' => 'virtual',
        ], (new PlainHookData)->toArray());
        $this->assertSame(1, PlainHookData::$backedReads);
        $this->assertSame(1, PlainHookData::$virtualReads);
    }

    /**
     * Test general transformation reads hooks only after property selection.
     */
    public function testGeneralTransformReadsHooksOnlyAfterSelection(): void
    {
        GeneralHookData::$visibleReads = 0;
        $data = (new GeneralHookData)
            ->only('value', 'hidden', 'excepted')
            ->except('excepted');

        $this->assertSame(['mapped_value' => 'VALUE'], $data->toArray());
        $this->assertSame(1, GeneralHookData::$visibleReads);
        $this->assertSame(
            ['value' => 'VALUE'],
            $data->transform(TransformationContextFactory::forPersistence()),
        );
        $this->assertSame(2, GeneralHookData::$visibleReads);
    }

    /**
     * Test backed set-only hooks normalize supplied values without a getter.
     */
    public function testBackedSetOnlyHooksRemainConstructableAndTransformable(): void
    {
        $data = BackedSetOnlyData::from(['name' => 'taylor']);

        $this->assertSame('TAYLOR', $data->name);
        $this->assertSame(['name' => 'TAYLOR'], $data->toArray());
    }

    /**
     * Test stored root contexts match fresh factory contexts.
     */
    public function testStoredRootContextsMatchFreshFactoryContexts(): void
    {
        $data = new SimpleData('value');
        $transformer = $this->app->make(DataTransformer::class);
        $storedDefault = $transformer->defaultContext($data);
        $storedAll = $transformer->allContext($data);

        $this->assertSame($storedDefault, $transformer->defaultContext($data));
        $this->assertSame($storedAll, $transformer->allContext($data));
        $this->assertSame($transformer->persistenceContext(), $transformer->persistenceContext());
        $this->assertEquals(
            TransformationContextFactory::create()->get($data),
            $storedDefault,
        );
        $this->assertEquals(
            TransformationContextFactory::create()->withoutValueTransformation()->get($data),
            $storedAll,
        );
        $this->assertEquals(
            TransformationContextFactory::forPersistence()->get($data),
            $transformer->persistenceContext(),
        );

        $defaultPartials = (new SimpleData('value'))
            ->include('value')
            ->excludePermanently('value');
        $firstDefault = $transformer->defaultContext($defaultPartials);
        $secondDefault = $transformer->defaultContext($defaultPartials);

        $this->assertNotSame($storedDefault, $firstDefault);
        $this->assertTrue($firstDefault->include?->selects('value'));
        $this->assertTrue($firstDefault->exclude?->selects('value'));
        $this->assertNull($secondDefault->include);
        $this->assertTrue($secondDefault->exclude?->selects('value'));

        $allPartials = (new SimpleData('value'))
            ->only('value')
            ->exceptPermanently('value');
        $firstAll = $transformer->allContext($allPartials);
        $secondAll = $transformer->allContext($allPartials);

        $this->assertNotSame($storedAll, $firstAll);
        $this->assertTrue($firstAll->only?->selects('value'));
        $this->assertTrue($firstAll->except?->selects('value'));
        $this->assertNull($secondAll->only);
        $this->assertTrue($secondAll->except?->selects('value'));
    }

    /**
     * Test all dispatches its cached context through the instance transform method.
     */
    public function testAllRetainsTheInstanceTransformationBoundary(): void
    {
        OverrideTransformData::$context = null;

        $this->assertSame(['value' => 'value'], (new OverrideTransformData('value'))->all());
        $this->assertInstanceOf(TransformationContext::class, OverrideTransformData::$context);
        $this->assertFalse(OverrideTransformData::$context->transformValues);
    }

    /**
     * Test operation transformers take precedence over fixed built-ins.
     */
    public function testUsesOperationTransformers(): void
    {
        $transformer = new class implements Transformer {
            public function transform(
                DataProperty $property,
                mixed $value,
                TransformationContext $context,
            ): string {
                return strtoupper((string) $value);
            }
        };
        $data = new SimpleData('value');
        $context = TransformationContextFactory::create()
            ->withTransformer('string', $transformer);

        $this->assertSame(['value' => 'VALUE'], $data->transform($context));
    }

    /**
     * Test lazy inclusion follows each lazy type's owning rule.
     */
    public function testIncludesAndExcludesLazyValues(): void
    {
        $closure = static fn (): string => 'closure';
        $data = new LazyValuesData(
            Lazy::create(static fn (): string => 'default'),
            Lazy::create(static fn (): string => 'included'),
            Lazy::create(static fn (): string => 'excluded')->defaultIncluded(),
            Lazy::when(static fn (): bool => true, static fn (): string => 'conditional'),
            Lazy::closure($closure),
        );

        $transformed = $data
            ->include('included')
            ->exclude('excluded', 'conditional', 'closure')
            ->toArray();

        $this->assertSame('included', $transformed['included']);
        $this->assertSame('conditional', $transformed['conditional']);
        $this->assertSame($closure, $transformed['closure']);
        $this->assertArrayNotHasKey('default', $transformed);
        $this->assertArrayNotHasKey('excluded', $transformed);
    }

    /**
     * Test automatic lazy values retain their owning transformation semantics.
     */
    public function testTransformsAutomaticLazyAndInertiaValues(): void
    {
        AutoLazyTransformNormalizer::$calls = 0;
        $data = AutoLazyTransformData::from([
            'child' => ['value' => 'nested'],
            'inertia' => 'lazy',
            'deferred' => 'deferred',
        ]);

        $withoutChild = $data->toArray();

        $this->assertSame(0, AutoLazyTransformNormalizer::$calls);
        $this->assertArrayNotHasKey('child', $withoutChild);
        $this->assertInstanceOf(OptionalProp::class, $withoutChild['inertia']);
        $this->assertInstanceOf(DeferProp::class, $withoutChild['deferred']);
        $this->assertSame('analytics', $withoutChild['deferred']->group());
        $this->assertTrue($withoutChild['deferred']->shouldRescue());

        $withChild = $data->include('child')->toArray();

        $this->assertSame(['value' => 'nested'], $withChild['child']);
        $this->assertInstanceOf(OptionalProp::class, $withChild['inertia']);
        $this->assertSame('lazy', $withChild['inertia']());
        $this->assertInstanceOf(DeferProp::class, $withChild['deferred']);
        $this->assertSame('analytics', $withChild['deferred']->group());
        $this->assertTrue($withChild['deferred']->shouldRescue());
        $this->assertSame('deferred', $withChild['deferred']());
        $this->assertSame(1, AutoLazyTransformNormalizer::$calls);
    }

    /**
     * Test only and except filter nested plain arrays without losing either mode.
     */
    public function testFiltersNestedArrays(): void
    {
        $data = new ArrayData([
            'first' => ['keep' => 1, 'remove' => 2],
            'second' => 3,
        ]);

        $this->assertSame([
            'meta' => ['first' => ['keep' => 1]],
        ], $data
            ->only('meta.first.keep')
            ->except('meta.first.remove')
            ->toArray());
    }

    /**
     * Test shallow nested partials preserve identity and individual lifetimes.
     */
    public function testPropagatesTemporaryAndPermanentPartialsFromAll(): void
    {
        $nested = new NestedLazyData(
            Lazy::create(static fn (): string => 'temporary'),
            Lazy::create(static fn (): string => 'permanent'),
        );
        $data = new PartialOwnerData($nested);

        $returned = $data
            ->include('nested.temporary')
            ->includePermanently('nested.permanent')
            ->all()['nested'];

        $this->assertSame($nested, $returned);
        $this->assertSame([
            'temporary' => 'temporary',
            'permanent' => 'permanent',
        ], $returned->toArray());
        $this->assertSame([
            'permanent' => 'permanent',
        ], $returned->toArray());
    }

    /**
     * Test propagated conditions are not re-evaluated against nested objects.
     */
    public function testPropagatesResolvedConditionsUnconditionally(): void
    {
        $nested = new NestedLazyData(
            Lazy::create(static fn (): string => 'value'),
            Lazy::create(static fn (): string => 'other'),
        );
        $data = new PartialOwnerData($nested);

        $returned = $data
            ->includeWhen(
                'nested.temporary',
                static fn (PartialOwnerData $owner): bool => $owner->enabled,
                permanent: true,
            )
            ->all()['nested'];

        $this->assertSame(['temporary' => 'value'], $returned->toArray());
        $this->assertSame(['temporary' => 'value'], $returned->toArray());
    }

    /**
     * Test all four partial modes propagate to unchanged nested data.
     */
    public function testPropagatesEveryPartialMode(): void
    {
        $nested = new NestedVisibleData('first', 'second', 'third');
        $data = new VisibleOwnerData($nested);

        $returned = $data
            ->include('nested.*')
            ->exclude('nested.lazy')
            ->only('nested.{first,second,lazy}')
            ->except('nested.second')
            ->all()['nested'];

        $this->assertSame([
            'first' => 'first',
        ], $returned->toArray());
    }

    /**
     * Test shallow partials propagate to each item in a typed raw array.
     */
    public function testPropagatesPartialsToTypedRawDataArrays(): void
    {
        $first = new NestedLazyData(
            Lazy::create(static fn (): string => 'first'),
            Lazy::create(static fn (): string => 'ignored'),
        );
        $second = new NestedLazyData(
            Lazy::create(static fn (): string => 'second'),
            Lazy::create(static fn (): string => 'ignored'),
        );
        $data = new DataArrayOwner([$first, $second]);

        $returned = $data->include('items.temporary')->all()['items'];

        $this->assertSame([$first, $second], $returned);
        $this->assertSame(['temporary' => 'first'], $returned[0]->toArray());
        $this->assertSame(['temporary' => 'second'], $returned[1]->toArray());
    }

    /**
     * Test a wildcard include propagates while an endpoint include does not.
     */
    public function testWildcardIncludesPropagateThroughNestedData(): void
    {
        $nested = new NestedLazyData(
            Lazy::create(static fn (): string => 'first'),
            Lazy::create(static fn (): string => 'second'),
        );

        $this->assertSame([
            'enabled' => true,
            'nested' => [
                'temporary' => 'first',
                'permanent' => 'second',
            ],
        ], (new PartialOwnerData($nested))->include('*')->toArray());

        $this->assertSame([
            'enabled' => true,
            'nested' => [],
        ], (new PartialOwnerData($nested))->include('nested')->toArray());
    }

    /**
     * Test nested instances apply all four of their own partial modes.
     */
    public function testAppliesNestedInstancePartials(): void
    {
        $nested = (new NestedLazyData(
            Lazy::create(static fn (): string => 'keep'),
            Lazy::create(static fn (): string => 'remove'),
        ))
            ->include('temporary', 'permanent')
            ->exclude('permanent')
            ->only('temporary', 'permanent')
            ->except('permanent');

        $this->assertSame([
            'enabled' => true,
            'nested' => ['temporary' => 'keep'],
        ], (new PartialOwnerData($nested))->toArray());
    }

    /**
     * Test parent and instance selections compose at the nested node.
     */
    public function testMergesParentAndNestedInstancePartials(): void
    {
        $nested = (new NestedLazyData(
            Lazy::create(static fn (): string => 'parent'),
            Lazy::create(static fn (): string => 'instance'),
        ))->includePermanently('permanent');

        $this->assertSame([
            'enabled' => true,
            'nested' => [
                'temporary' => 'parent',
                'permanent' => 'instance',
            ],
        ], (new PartialOwnerData($nested))
            ->includePermanently('nested.temporary')
            ->toArray());

        $visible = (new NestedVisibleData('first', 'second', 'third'))
            ->onlyPermanently('first');

        $this->assertSame([
            'nested' => ['first' => 'first'],
        ], (new VisibleOwnerData($visible))->onlyPermanently('*')->toArray());
    }

    /**
     * Test repeated references consume temporary partials only at first reach.
     */
    public function testNestedInstancePartialLifetimeFollowsEachReachedOccurrence(): void
    {
        $temporary = (new NestedLazyData(
            Lazy::create(static fn (): string => 'temporary'),
            Lazy::create(static fn (): string => 'ignored'),
        ))->include('temporary');
        $permanent = (new NestedLazyData(
            Lazy::create(static fn (): string => 'ignored'),
            Lazy::create(static fn (): string => 'permanent'),
        ))->includePermanently('permanent');

        $this->assertSame([
            ['temporary' => 'temporary'],
            [],
            ['permanent' => 'permanent'],
            ['permanent' => 'permanent'],
        ], (new DataArrayOwner([
            $temporary,
            $temporary,
            $permanent,
            $permanent,
        ]))->toArray()['items']);
    }

    /**
     * Test deeply nested typed-array items keep instance partials isolated.
     */
    public function testKeepsNestedTypedArrayItemPartialsIsolated(): void
    {
        $items = [
            new PartialArrayItemData([
                (new NestedLazyData(
                    Lazy::create(static fn (): string => 'B1'),
                    Lazy::create(static fn (): string => 'ignored'),
                ))->include('temporary'),
                new NestedLazyData(
                    Lazy::create(static fn (): string => 'B2'),
                    Lazy::create(static fn (): string => 'ignored'),
                ),
            ]),
            new PartialArrayItemData([
                new NestedLazyData(
                    Lazy::create(static fn (): string => 'D1'),
                    Lazy::create(static fn (): string => 'ignored'),
                ),
                (new NestedLazyData(
                    Lazy::create(static fn (): string => 'ignored'),
                    Lazy::create(static fn (): string => 'D2'),
                ))->include('permanent'),
            ]),
        ];

        $this->assertSame([
            ['nestedCollection' => [
                ['temporary' => 'B1'],
                [],
            ]],
            ['nestedCollection' => [
                [],
                ['permanent' => 'D2'],
            ]],
        ], (new PartialArrayOwnerData(
            Lazy::create(static fn (): array => $items),
        ))->include('items')->toArray()['items']);
    }

    /**
     * Test non-transformable data values retain their identity.
     */
    public function testRetainsNonTransformableNestedAndIterableDataValues(): void
    {
        $nested = new SimpleDto('nested');
        $iterable = new SimpleDto('iterable');
        $data = new DtoOwnerData($nested, [$iterable]);

        $this->assertSame([
            'nested' => $nested,
            'items' => [$iterable],
        ], $data->toArray());
        $this->assertSame($nested, $data->all()['nested']);
    }

    /**
     * Test a modular collectable without transformation capability remains unchanged.
     */
    public function testRetainsNonTransformableCustomDataCollectables(): void
    {
        $collectable = new SimpleDtoCollectable([new SimpleDto('value')]);
        $data = new DtoCollectableOwnerData($collectable);

        $this->assertSame($collectable, $data->toArray()['items']);
    }

    /**
     * Test collection, parent, and item partials compose across the complete graph.
     */
    public function testComposesPartialsAcrossDataCollectionGraphs(): void
    {
        $collection = new DataCollection(PartialCollectionItemData::class, [
            new PartialCollectionItemData(
                new NestedLazyData(
                    Lazy::create(static fn (): string => 'A'),
                    Lazy::create(static fn (): string => 'ignored'),
                ),
                [
                    (new NestedLazyData(
                        Lazy::create(static fn (): string => 'B1'),
                        Lazy::create(static fn (): string => 'ignored'),
                    ))->include('temporary'),
                    new NestedLazyData(
                        Lazy::create(static fn (): string => 'B2'),
                        Lazy::create(static fn (): string => 'ignored'),
                    ),
                ],
            ),
            new PartialCollectionItemData(
                new NestedLazyData(
                    Lazy::create(static fn (): string => 'C'),
                    Lazy::create(static fn (): string => 'ignored'),
                ),
                [
                    new NestedLazyData(
                        Lazy::create(static fn (): string => 'D1'),
                        Lazy::create(static fn (): string => 'ignored'),
                    ),
                    (new NestedLazyData(
                        Lazy::create(static fn (): string => 'ignored'),
                        Lazy::create(static fn (): string => 'D2'),
                    ))->include('permanent'),
                ],
            ),
        ]);
        $collection->include('nested.temporary');
        $data = new PartialCollectionOwnerData(Lazy::create(static fn (): DataCollection => $collection));

        $this->assertSame([
            'collection' => [
                [
                    'nested' => ['temporary' => 'A'],
                    'nestedCollection' => [
                        ['temporary' => 'B1'],
                        [],
                    ],
                ],
                [
                    'nested' => ['temporary' => 'C'],
                    'nestedCollection' => [
                        [],
                        ['permanent' => 'D2'],
                    ],
                ],
            ],
        ], $data->include('collection')->toArray());
    }

    /**
     * Test all propagates parent partials to a returned lazy data collection.
     */
    public function testPropagatesPartialsFromAllToLazyDataCollections(): void
    {
        $collection = new DataCollection(NestedLazyData::class, [
            new NestedLazyData(
                Lazy::create(static fn (): string => 'first'),
                Lazy::create(static fn (): string => 'ignored'),
            ),
            new NestedLazyData(
                Lazy::create(static fn (): string => 'second'),
                Lazy::create(static fn (): string => 'ignored'),
            ),
        ]);
        $data = new NestedCollectionOwnerData(Lazy::create(static fn (): DataCollection => $collection));

        $returned = $data->include('collection.temporary')->all()['collection'];

        $this->assertSame($collection, $returned);
        $this->assertSame([
            ['temporary' => 'first'],
            ['temporary' => 'second'],
        ], $returned->toArray());
    }

    /**
     * Test nested transformation stops at the configured depth.
     */
    public function testThrowsAtMaximumTransformationDepth(): void
    {
        $data = new NestedData(new NestedData(new SimpleData('deep')));

        $this->expectExceptionMessageIsOrContains('Max transformation depth of 1 reached.');

        $data->transform(TransformationContextFactory::create()->maxDepth(1));
    }

    /**
     * Test persistence transforms the complete constructable graph without changing partial stores.
     */
    public function testTransformsCompleteConstructableGraphWithoutChangingPartials(): void
    {
        $nested = (new ConstructableNestedData(
            'nested',
            'nested-secret',
            Lazy::create(static fn (): string => 'nested-default'),
        ))
            ->include('defaultLazy')
            ->excludePermanently('secret');
        $item = (new ConstructableNestedData(
            'item',
            'item-secret',
            Lazy::create(static fn (): string => 'item-default'),
        ))
            ->only('name')
            ->includePermanently('defaultLazy');
        $items = (new DataCollection(ConstructableNestedData::class, [$item]))
            ->only('name')
            ->exceptPermanently('secret');
        $data = (new ConstructableGraphData(
            'root',
            'root-secret',
            $nested,
            $items,
            Lazy::create(static fn (): string => 'root-default'),
            Lazy::when(static fn (): bool => true, static fn (): string => 'root-conditional'),
        ))
            ->only('name')
            ->exceptPermanently('secret')
            ->additional([
                'name' => 'response-name',
                'responseOnly' => true,
            ]);

        $rootPartials = $data->getPartialsDefinition()->resolve($data);
        $nestedPartials = $nested->getPartialsDefinition()->resolve($nested);
        $collectionPartials = $items->getPartialsDefinition()->resolve($items);
        $itemPartials = $item->getPartialsDefinition()->resolve($item);

        $this->assertSame([
            'name' => 'root',
            'secret' => 'root-secret',
            'nested' => [
                'name' => 'nested',
                'secret' => 'nested-secret',
                'defaultLazy' => 'nested-default',
            ],
            'items' => [[
                'name' => 'item',
                'secret' => 'item-secret',
                'defaultLazy' => 'item-default',
            ]],
            'defaultLazy' => 'root-default',
            'conditionalLazy' => 'root-conditional',
        ], $data->transform(TransformationContextFactory::forPersistence()));
        $this->assertSame($rootPartials, $data->getPartialsDefinition()->resolve($data));
        $this->assertSame($nestedPartials, $nested->getPartialsDefinition()->resolve($nested));
        $this->assertSame($collectionPartials, $items->getPartialsDefinition()->resolve($items));
        $this->assertSame($itemPartials, $item->getPartialsDefinition()->resolve($item));
    }

    /**
     * Test persistence rejects lazy callback values.
     */
    public function testPersistenceRejectsLazyCallbackValues(): void
    {
        $data = new ConstructableLazyData(Lazy::closure(static fn (): string => 'value'));

        $this->expectException(CannotTransformData::class);
        $this->expectExceptionMessageIsOrContains('Lazy property [' . ConstructableLazyData::class . '::$value] does not resolve to constructable data.');

        $data->transform(TransformationContextFactory::forPersistence());
    }

    /**
     * Test persistence rejects an excluded conditional value without resolving it.
     */
    public function testPersistenceRejectsExcludedConditionalLazyWithoutResolvingIt(): void
    {
        $calls = 0;
        $data = new ConstructableLazyData(Lazy::when(
            static fn (): bool => false,
            function () use (&$calls): string {
                ++$calls;

                return 'value';
            },
        ));

        try {
            $data->transform(TransformationContextFactory::forPersistence());
            $this->fail('Expected an excluded conditional lazy value to be rejected.');
        } catch (CannotTransformData $exception) {
            $this->assertStringContainsString('does not resolve to constructable data', $exception->getMessage());
        }

        $this->assertSame(0, $calls);
    }

    /**
     * Test persistence rejects an unloaded relation without reading it.
     */
    public function testPersistenceRejectsUnloadedRelationalLazyWithoutReadingIt(): void
    {
        $model = new ConstructableLazyModel;
        $data = new ConstructableLazyData(Lazy::whenLoaded(
            'related',
            $model,
            static fn (): string => 'value',
        ));

        try {
            $data->transform(TransformationContextFactory::forPersistence());
            $this->fail('Expected an unloaded relational lazy value to be rejected.');
        } catch (CannotTransformData $exception) {
            $this->assertStringContainsString('does not resolve to constructable data', $exception->getMessage());
        }

        $this->assertSame(0, $model->relationReads);
    }

    /**
     * Test persistence resolves included conditional and loaded relational values.
     */
    public function testPersistenceResolvesIncludedConditionalAndLoadedRelationalValues(): void
    {
        $model = new ConstructableLazyModel;
        $model->setRelation('related', 'loaded');
        $data = new ConstructableLazyPairData(
            Lazy::when(static fn (): bool => true, static fn (): string => 'conditional'),
            Lazy::whenLoaded('related', $model, static fn (): string => 'relational'),
        );

        $this->assertSame([
            'conditional' => 'conditional',
            'relational' => 'relational',
        ], $data->transform(TransformationContextFactory::forPersistence()));
        $this->assertSame(1, $model->relationReads);
    }
}

class BulkCopyRecordingDataTransformer extends DataTransformer
{
    public int $bulkCopyCalls = 0;

    /** @var list<class-string<BaseData>> */
    public array $bulkCopiedClasses = [];

    /**
     * Record and perform one bulk-copy transformation.
     */
    protected function transformBulkCopy(BaseData $data, DataClass $dataClass): array
    {
        ++$this->bulkCopyCalls;
        $this->bulkCopiedClasses[] = $data::class;

        return parent::transformBulkCopy($data, $dataClass);
    }
}

enum Status: string
{
    case Ready = 'ready';
}

class SimpleData extends Data
{
    public function __construct(public string $value)
    {
    }
}

class PropertyTransformedData extends Data
{
    public function __construct(
        #[WithTransformer(UppercasePropertyTransformer::class)]
        public string $value,
    ) {
    }
}

class UppercasePropertyTransformer implements Transformer
{
    /**
     * Transform the fixture value to uppercase.
     */
    public function transform(
        DataProperty $property,
        mixed $value,
        TransformationContext $context,
    ): string {
        return strtoupper((string) $value);
    }
}

class AnnotatedArrayOwnerData extends Data
{
    /**
     * @param list<SimpleData> $items
     */
    public function __construct(public array $items)
    {
    }
}

class OverrideTransformData extends Data
{
    public static ?TransformationContext $context = null;

    public function __construct(public string $value)
    {
    }

    /**
     * Capture the context supplied through the public transformation boundary.
     */
    public function transform(
        TransformationContextFactory|TransformationContext|null $transformationContext = null,
    ): array {
        self::$context = $transformationContext instanceof TransformationContext
            ? $transformationContext
            : null;

        return parent::transform($transformationContext);
    }
}

class PlainOrderParentData extends Data
{
    protected string $redeclared = 'parent-redeclared';

    public string $parent = 'parent';
}

#[AllowDynamicProperties]
class PlainOrderData extends PlainOrderParentData
{
    public string $child = 'child';

    public string $redeclared = 'child-redeclared';

    public string $uninitialized;
}

class PlainHookData extends Data
{
    public static int $backedReads = 0;

    public static int $virtualReads = 0;

    public string $backed = 'backed' {
        get {
            ++self::$backedReads;

            return strtoupper($this->backed);
        }
    }

    public string $virtual {
        get {
            ++self::$virtualReads;

            return 'virtual';
        }
    }
}

class GeneralHookData extends Data
{
    public static int $visibleReads = 0;

    #[MapOutputName('mapped_value')]
    public string $value = 'value' {
        get {
            ++self::$visibleReads;

            return strtoupper($this->value);
        }
    }

    #[Hidden]
    public string $hidden {
        get {
            throw new RuntimeException('Hidden getter should not run.');
        }
    }

    public string $excepted {
        get {
            throw new RuntimeException('Excepted getter should not run.');
        }
    }

    public string $unselected {
        get {
            throw new RuntimeException('Unselected getter should not run.');
        }
    }
}

class BackedSetOnlyData extends Data
{
    public string $name = '' {
        set {
            $this->name = strtoupper($value);
        }
    }
}

class SimpleDto extends Dto
{
    public function __construct(
        #[MapOutputName('mapped_value')]
        public string $value,
    ) {
    }
}

class DtoOwnerData extends Data
{
    /**
     * @param list<SimpleDto> $items
     */
    public function __construct(
        public SimpleDto $nested,
        #[DataCollectionOf(SimpleDto::class)]
        public array $items,
    ) {
    }
}

/** @implements BaseDataCollectable<int, SimpleDto> */
class SimpleDtoCollectable implements BaseDataCollectable
{
    /**
     * @param list<SimpleDto> $items
     */
    public function __construct(public array $items)
    {
    }

    /**
     * Get the data class stored by the collection.
     */
    public function getDataClass(): string
    {
        return SimpleDto::class;
    }

    /**
     * Get an iterator for the data items.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}

class DtoCollectableOwnerData extends Data
{
    public function __construct(public BaseDataCollectable $items)
    {
    }
}

class TransformingData extends Data
{
    public function __construct(
        #[MapOutputName('display_name')]
        public string $name,
        public DateTimeImmutable $createdAt,
        public BackedEnum $status,
        public SimpleData $nested,
    ) {
    }
}

class RecipeOutputData extends Data
{
    #[Computed]
    public string $computed = 'computed';

    #[Hidden]
    public string $hidden = 'hidden';

    public string $uninitialized;

    public function __construct(
        #[MapOutputName('display_name')]
        public string $name,
        public DateTimeImmutable $createdAt,
        public Status $status,
        public SimpleData $nested,
    ) {
    }
}

class PaginatorItemData extends Data
{
    public function __construct(
        public int $id,
        #[MapOutputName('label_text')]
        public string $label,
    ) {
    }
}

class PaginatorOwnerData extends Data
{
    /**
     * Create a nested paginator fixture.
     *
     * @param LengthAwarePaginator<int, PaginatorItemData> $offset
     * @param CursorPaginator<int, PaginatorItemData> $cursor
     */
    public function __construct(
        public LengthAwarePaginator $offset,
        public CursorPaginator $cursor,
    ) {
    }
}

class LazyValuesData extends Data
{
    public function __construct(
        public Lazy|string $default,
        public Lazy|string $included,
        public Lazy|string $excluded,
        public Lazy|string $conditional,
        public Closure|Lazy $closure,
    ) {
    }
}

class AutoLazyTransformData extends Data
{
    public function __construct(
        #[AutoLazy]
        public Lazy|AutoLazyTransformChildData $child,
        #[AutoInertiaLazy]
        public Lazy|string $inertia,
        #[AutoInertiaDeferred('analytics', rescue: true)]
        public Lazy|string $deferred,
    ) {
    }
}

class AutoLazyTransformChildData extends Data
{
    public function __construct(
        public string $value,
    ) {
    }

    public static function normalizers(): array
    {
        return [AutoLazyTransformNormalizer::class];
    }
}

class AutoLazyTransformNormalizer implements Normalizer
{
    public static int $calls = 0;

    public function normalize(mixed $value): array|Normalized|null
    {
        ++self::$calls;

        return null;
    }
}

class ConstructableGraphData extends Data
{
    #[Computed]
    public string $summary = 'computed';

    public string $virtual {
        get => 'virtual';
    }

    public function __construct(
        #[MapOutputName('display_name')]
        public string $name,
        #[Hidden]
        public string $secret,
        public ConstructableNestedData $nested,
        #[DataCollectionOf(ConstructableNestedData::class)]
        public DataCollection $items,
        public Lazy|string $defaultLazy,
        public Lazy|string $conditionalLazy,
    ) {
    }
}

class ConstructableNestedData extends Data
{
    #[Computed]
    public string $summary = 'computed';

    public function __construct(
        #[MapOutputName('display_name')]
        public string $name,
        #[Hidden]
        public string $secret,
        public Lazy|string $defaultLazy,
    ) {
    }
}

class ConstructableLazyData extends Data
{
    public function __construct(public Lazy|string $value)
    {
    }
}

class ConstructableLazyPairData extends Data
{
    public function __construct(
        public Lazy|string $conditional,
        public Lazy|string $relational,
    ) {
    }
}

class ConstructableLazyModel extends Model
{
    public int $relationReads = 0;

    /**
     * Get a relationship value from the model.
     */
    public function getRelationValue(string $key): mixed
    {
        ++$this->relationReads;

        return parent::getRelationValue($key);
    }
}

class ArrayData extends Data
{
    public function __construct(public array $meta)
    {
    }
}

class NestedLazyData extends Data
{
    public function __construct(
        public Lazy|string $temporary,
        public Lazy|string $permanent,
    ) {
    }
}

class PartialOwnerData extends Data
{
    public bool $enabled = true;

    public function __construct(public NestedLazyData $nested)
    {
    }
}

class NestedVisibleData extends Data
{
    public Lazy|string $lazy;

    public function __construct(
        public string $first,
        public string $second,
        public string $third,
    ) {
        $this->lazy = Lazy::create(static fn (): string => 'lazy')->defaultIncluded();
    }
}

class VisibleOwnerData extends Data
{
    public function __construct(public NestedVisibleData $nested)
    {
    }
}

class DataArrayOwner extends Data
{
    /**
     * @param list<NestedLazyData> $items
     */
    public function __construct(
        #[DataCollectionOf(NestedLazyData::class)]
        public array $items,
    ) {
    }
}

class PartialArrayItemData extends Data
{
    /**
     * @param list<NestedLazyData> $nestedCollection
     */
    public function __construct(
        #[DataCollectionOf(NestedLazyData::class)]
        public array $nestedCollection,
    ) {
    }
}

class PartialArrayOwnerData extends Data
{
    /**
     * @param Lazy|list<PartialArrayItemData> $items
     */
    public function __construct(
        #[DataCollectionOf(PartialArrayItemData::class)]
        public Lazy|array $items,
    ) {
    }
}

class PartialCollectionItemData extends Data
{
    /**
     * @param list<NestedLazyData> $nestedCollection
     */
    public function __construct(
        public NestedLazyData $nested,
        #[DataCollectionOf(NestedLazyData::class)]
        public array $nestedCollection,
    ) {
    }
}

class PartialCollectionOwnerData extends Data
{
    public function __construct(
        #[DataCollectionOf(PartialCollectionItemData::class)]
        public Lazy|DataCollection $collection,
    ) {
    }
}

class NestedCollectionOwnerData extends Data
{
    public function __construct(
        #[DataCollectionOf(NestedLazyData::class)]
        public Lazy|DataCollection $collection,
    ) {
    }
}

class NestedData extends Data
{
    public function __construct(public Data $nested)
    {
    }
}
