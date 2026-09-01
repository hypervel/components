<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Support\Transformation\DataTransformerTest;

use BackedEnum;
use Closure;
use DateTimeImmutable;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataProperty;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Data\Transformers\Transformer;
use Hypervel\Testbench\TestCase;

class DataTransformerTest extends TestCase
{
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
     * Test nested transformation stops at the configured depth.
     */
    public function testThrowsAtMaximumTransformationDepth(): void
    {
        $data = new NestedData(new NestedData(new SimpleData('deep')));

        $this->expectExceptionMessage('Max transformation depth of 1 reached.');

        $data->transform(TransformationContextFactory::create()->maxDepth(1));
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

class NestedData extends Data
{
    public function __construct(public Data $nested)
    {
    }
}
