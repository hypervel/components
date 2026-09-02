<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Transformation\DataTransformerTest;

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
use Hypervel\Data\Contracts\BaseDataCollectable;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Exceptions\CannotTransformData;
use Hypervel\Data\Lazy;
use Hypervel\Data\Normalizers\Normalized\Normalized;
use Hypervel\Data\Normalizers\Normalizer;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Data\Transformers\Transformer;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Inertia\DeferProp;
use Hypervel\Inertia\OptionalProp;
use Hypervel\Testbench\TestCase;
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

        $this->expectExceptionMessage('Max transformation depth of 1 reached.');

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
        $this->expectExceptionMessage('Lazy property [' . ConstructableLazyData::class . '::$value] does not resolve to constructable data.');

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
