<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Eloquent\DataCollectionEloquentCastTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Eloquent\DataCollectionEloquentCast;
use Hypervel\Data\Exceptions\CannotCastData;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Database\Eloquent\Casts\Json;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Fluent;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\AbstractData\AbstractData;
use Hypervel\Tests\Data\Fixtures\AbstractData\AbstractDataA;
use Hypervel\Tests\Data\Fixtures\AbstractData\AbstractDataB;
use Hypervel\Tests\Data\Fixtures\AbstractPropertyMorphableData;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\LazyData;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithCasts;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithCustomCollectionCasts;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithDefaultCasts;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithEncryptedCasts;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithJson;
use Hypervel\Tests\Data\Fixtures\MultiData;
use Hypervel\Tests\Data\Fixtures\PropertyMorphableDataA;
use Hypervel\Tests\Data\Fixtures\PropertyMorphableDataB;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataCollection;
use JsonException;
use RuntimeException;
use stdClass;

class DataCollectionEloquentCastTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Get package providers for the data collection cast test application.
     */
    protected function getPackageProviders(Application $app): array
    {
        return [DataServiceProvider::class];
    }

    /**
     * Define deterministic encryption configuration before providers boot.
     */
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->make('config');
        $config->set('app.cipher', 'AES-256-CBC');
        $config->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));
        $config->set('app.previous_keys', []);
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

    public function testCanSaveADataCollection(): void
    {
        DummyModelWithCasts::create([
            'data_collection' => SimpleData::collect([
                'Hello',
                'World',
            ], DataCollection::class),
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data_collection' => json_encode([
                ['string' => 'Hello'],
                ['string' => 'World'],
            ]),
        ]);
    }

    public function testCanSaveADataObjectAsAnArray(): void
    {
        DummyModelWithCasts::create([
            'data_collection' => [
                ['string' => 'Hello'],
                ['string' => 'World'],
            ],
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data_collection' => json_encode([
                ['string' => 'Hello'],
                ['string' => 'World'],
            ]),
        ]);
    }

    public function testCanSaveADataObjectAsAnArrayFromACollection(): void
    {
        DummyModelWithCasts::create([
            'data_collection' => new Collection([
                ['string' => 'Hello'],
                ['string' => 'World'],
            ]),
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data_collection' => json_encode([
                ['string' => 'Hello'],
                ['string' => 'World'],
            ]),
        ]);
    }

    public function testCanLoadADataObject(): void
    {
        DB::table('dummy_model_with_casts')->insert([
            'data_collection' => json_encode([
                ['string' => 'Hello'],
                ['string' => 'World'],
            ]),
        ]);

        $this->assertEquals(new DataCollection(SimpleData::class, [
            new SimpleData('Hello'),
            new SimpleData('World'),
        ]), DummyModelWithCasts::first()->data_collection);
    }

    public function testCanSaveANullAsAValue(): void
    {
        DummyModelWithCasts::create([
            'data_collection' => null,
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data_collection' => null,
        ]);
    }

    public function testCanLoadNullAsAValue(): void
    {
        DB::table('dummy_model_with_casts')->insert([
            'data_collection' => null,
        ]);

        $this->assertNull(DummyModelWithCasts::first()->data_collection);
    }

    public function testCanSaveACustomDataCollection(): void
    {
        DummyModelWithCustomCollectionCasts::create([
            'data_collection' => [
                ['string' => 'Hello'],
                ['string' => 'World'],
            ],
        ]);

        // Eloquent's JSON codec encodes the stored value, so the collection's pretty-printing toJson() doesn't apply
        // (README).
        $this->assertDatabaseHas(DummyModelWithCustomCollectionCasts::class, [
            'data_collection' => json_encode([
                ['string' => 'Hello'],
                ['string' => 'World'],
            ]),
        ]);
    }

    public function testRetrievesCustomDataCollection(): void
    {
        DB::table('dummy_model_with_casts')->insert([
            'data_collection' => json_encode([
                ['string' => 'Hello'],
                ['string' => 'World'],
            ]),
        ]);

        $this->assertEquals(new SimpleDataCollection(
            SimpleData::class,
            [
                new SimpleData('Hello'),
                new SimpleData('World'),
            ]
        ), DummyModelWithCustomCollectionCasts::first()->data_collection);
    }

    public function testLoadsACustomDataCollectionWhenNullableArgumentUsedAndValueIsNullInDatabase(): void
    {
        DB::table('dummy_model_with_casts')->insert([
            'data' => null,
        ]);

        $collection = DummyModelWithDefaultCasts::first()->data_collection;

        $this->assertInstanceOf(SimpleDataCollection::class, $collection);
        $this->assertCount(0, $collection);
    }

    public function testCanUseAnAbstractDataCollectionWithMultipleChildren(): void
    {
        $abstractA = new AbstractDataA('A\A');
        $abstractB = new AbstractDataB('B\B');

        $modelId = DummyModelWithCasts::create([
            'abstract_collection' => [$abstractA, $abstractB],
        ])->id;

        $model = DummyModelWithCasts::find($modelId);

        $this->assertInstanceOf(DataCollection::class, $model->abstract_collection);
        $this->assertContainsOnlyInstancesOf(AbstractData::class, $model->abstract_collection->items());
        $this->assertInstanceOf(AbstractDataA::class, $model->abstract_collection[0]);
        $this->assertInstanceOf(AbstractDataB::class, $model->abstract_collection[1]);
    }

    public function testCanLoadAndSaveAnAbstractPropertyMorphableDataCollection(): void
    {
        $modelClass = new class extends Model {
            protected array $guarded = [];

            protected array $casts = [
                'data_collection' => SimpleDataCollection::class . ':' . AbstractPropertyMorphableData::class,
            ];

            protected ?string $table = 'dummy_model_with_casts';

            public bool $timestamps = false;
        };

        $abstractA = new PropertyMorphableDataA('foo', DummyBackedEnum::FOO);
        $abstractB = new PropertyMorphableDataB('bar');

        $modelId = $modelClass::create([
            'data_collection' => [$abstractA, $abstractB],
        ])->id;

        // Eloquent's JSON codec encodes the stored value, rather than the collection's pretty-printing toJson().
        $this->assertDatabaseHas($modelClass::class, [
            'data_collection' => json_encode([
                ['a' => 'foo', 'enum' => 'foo', 'variant' => 'a'],
                ['b' => 'bar', 'variant' => 'b'],
            ]),
        ]);

        $model = $modelClass::find($modelId);

        $this->assertInstanceOf(PropertyMorphableDataA::class, $model->data_collection[0]);
        $this->assertSame('foo', $model->data_collection[0]->a);
        $this->assertSame(DummyBackedEnum::FOO, $model->data_collection[0]->enum);
        $this->assertInstanceOf(PropertyMorphableDataB::class, $model->data_collection[1]);
        $this->assertSame('bar', $model->data_collection[1]->b);
    }

    public function testCanSaveADataCollectionWithLazyPropertiesWhichGetResolved(): void
    {
        DummyModelWithCasts::create([
            'lazy_data_collection' => LazyData::collect([
                LazyData::fromString('Hello'),
                LazyData::fromString('World'),
            ], DataCollection::class),
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'lazy_data_collection' => json_encode([
                ['name' => 'Hello'],
                ['name' => 'World'],
            ]),
        ]);
    }

    public function testCanCorrectlyDetectIfTheAttributeIsDirty(): void
    {
        // Set a raw JSON string with spaces in it to mimic database behavior
        $model = new DummyModelWithJson;
        $model->setRawAttributes(['data_collection' => '[{"second": "Second", "first": "First"}, {"first": "Third", "second": "Fourth"}]']);
        $model->save();

        $model->data_collection = [
            new MultiData('First', 'Second'),
            new MultiData('Third', 'Fourth'),
        ];

        $this->assertSame('[{"second": "Second", "first": "First"}, {"first": "Third", "second": "Fourth"}]', $model->getRawOriginal('data_collection'));
        $this->assertSame('[{"first":"First","second":"Second"},{"first":"Third","second":"Fourth"}]', $model->getAttributes()['data_collection']);
        $this->assertFalse($model->isDirty('data_collection'));
    }

    public function testFlagsTheAttributeAsDirtyWhenItIsEncryptedAndThereArePreviousEncryptionKeys(): void
    {
        // The encrypter reads app.previous_keys when it is created, so the key is added to it directly.
        try {
            Crypt::previousKeys([random_bytes(32)]);

            $model = new DummyModelWithEncryptedCasts;
            $model->data_collection = [
                new SimpleData('First'),
                new SimpleData('Second'),
            ];
            $model->save();

            $model->data_collection = $model->data_collection;

            $this->assertNotSame($model->getAttributes()['data_collection'], $model->getRawOriginal('data_collection'));
            $this->assertTrue($model->isDirty('data_collection'));
        } finally {
            Crypt::previousKeys([]);
        }
    }

    public function testDoesNotFlagTheAttributeAsDirtyWhenItIsEncryptedAndThereAreNoPreviousEncryptionKeys(): void
    {
        $model = new DummyModelWithEncryptedCasts;
        $model->data_collection = [
            new SimpleData('First'),
            new SimpleData('Second'),
        ];
        $model->save();

        $model->data_collection = $model->data_collection;

        $this->assertNotSame($model->getAttributes()['data_collection'], $model->getRawOriginal('data_collection'));
        $this->assertFalse($model->isDirty('data_collection'));
    }

    public function testCanUpdateAModelWhereTheCastIsInitiallyNull(): void
    {
        $model = new DummyModelWithCasts;
        $model->setRawAttributes(['data_collection' => null]);
        $model->save();

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data_collection' => null,
        ]);

        $model->update([
            'data_collection' => Collection::make([new SimpleData('Test')]),
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data_collection' => json_encode([['string' => 'Test']]),
        ]);
    }

    public function testCanUpdateAModelWhereTheCastIsInitiallyNotNull(): void
    {
        $model = DummyModelWithCasts::create([
            'data_collection' => Collection::make([new SimpleData('Test')]),
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data_collection' => json_encode([['string' => 'Test']]),
        ]);

        $model->update([
            'data_collection' => null,
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data_collection' => null,
        ]);
    }

    public function testCollectionCastKeepsItemKeys(): void
    {
        $model = new CollectionCastModel;
        $model->items = new DataCollection(CollectionItemData::class, [
            'first' => new CollectionItemData('Taylor'),
            'second' => new CollectionItemData('Abigail'),
        ]);

        $this->assertSame([
            'first' => ['name' => 'Taylor'],
            'second' => ['name' => 'Abigail'],
        ], Json::decode($model->getAttributes()['items']));

        $model = new CollectionCastModel;
        $model->setRawAttributes([
            'items' => '{"first":{"name":"Taylor"},"second":{"name":"Abigail"}}',
        ]);

        $this->assertSame(['first', 'second'], array_keys($model->items->items()));
        $this->assertEquals(new CollectionItemData('Taylor'), $model->items['first']);
        $this->assertEquals(new CollectionItemData('Abigail'), $model->items['second']);
    }

    public function testCollectionCastAcceptsCollectionsAndOtherArrayables(): void
    {
        $model = new CollectionCastModel;
        $model->graph_items = Collection::make([
            new CollectionGraphItemData(
                name: 'Taylor',
                secret: 'private',
                lazy: Lazy::create(static fn (): string => 'resolved'),
            ),
        ]);

        $this->assertSame([
            ['name' => 'Taylor', 'secret' => 'private', 'lazy' => 'resolved'],
        ], Json::decode($model->getAttributes()['graph_items']));

        $model->items = new Fluent([
            'first' => ['name' => 'Taylor'],
            'second' => new CollectionItemData('Abigail'),
        ]);

        $this->assertSame([
            'first' => ['name' => 'Taylor'],
            'second' => ['name' => 'Abigail'],
        ], Json::decode($model->getAttributes()['items']));
    }

    public function testStoredCollectionsUseOneInternalRootItemOperation(): void
    {
        CollectionInternalOperationData::$normalizerCalls = 0;
        $caster = new DataCollectionEloquentCast(CollectionInternalOperationData::class);
        $items = $caster->get(
            new CollectionCastModel,
            'items',
            '[{"name":"Taylor"},{"name":"Abigail"}]',
            [],
        );

        $this->assertSame(1, CollectionInternalOperationData::$normalizerCalls);
        $this->assertSame(['Taylor', 'Abigail'], array_column($items->items(), 'name'));
    }

    public function testCollectionCastPersistsCompleteItemsWithoutMutatingPartials(): void
    {
        $first = (new CollectionGraphItemData(
            name: 'Taylor',
            secret: 'private',
            lazy: Lazy::create(static fn (): string => 'resolved'),
        ))->only('name');
        $second = (new CollectionGraphItemData(
            name: 'Abigail',
            secret: 'private-two',
            lazy: Lazy::create(static fn (): string => 'resolved-two'),
        ))->except('secret');
        $collection = (new DataCollection(CollectionGraphItemData::class, [$first, $second]))
            ->only('name');

        $collectionPartials = $collection->getPartialsDefinition()->resolve($collection);
        $firstPartials = $first->getPartialsDefinition()->resolve($first);
        $secondPartials = $second->getPartialsDefinition()->resolve($second);

        $model = new CollectionCastModel;
        $model->graph_items = $collection;

        $this->assertSame([
            [
                'name' => 'Taylor',
                'secret' => 'private',
                'lazy' => 'resolved',
            ],
            [
                'name' => 'Abigail',
                'secret' => 'private-two',
                'lazy' => 'resolved-two',
            ],
        ], Json::decode($model->getAttributes()['graph_items']));
        $this->assertSame($collectionPartials, $collection->getPartialsDefinition()->resolve($collection));
        $this->assertSame($firstPartials, $first->getPartialsDefinition()->resolve($first));
        $this->assertSame($secondPartials, $second->getPartialsDefinition()->resolve($second));
    }

    public function testCollectionCastUsesOneContextThroughEachItemTransformationBoundary(): void
    {
        CollectionOverrideData::$contexts = [];
        $model = new CollectionCastModel;
        $model->override_items = [
            new CollectionOverrideData('Taylor'),
            new CollectionOverrideData('Abigail'),
        ];

        $this->assertSame([
            ['name' => 'Taylor'],
            ['name' => 'Abigail'],
        ], Json::decode($model->getAttributes()['override_items']));
        $this->assertCount(2, CollectionOverrideData::$contexts);
        $this->assertSame(CollectionOverrideData::$contexts[0], CollectionOverrideData::$contexts[1]);
        $this->assertTrue(CollectionOverrideData::$contexts[0]->constructable);
    }

    public function testCollectionDefaultUsesItsLateBoundEmptyListRepresentation(): void
    {
        $decoded = null;
        $model = new CollectionCastModel;
        $model->setRawAttributes(['default_items' => null]);

        try {
            Json::decodeUsing(function (mixed $value) use (&$decoded): array {
                $decoded = $value;

                return [];
            });

            $this->assertInstanceOf(DataCollection::class, $model->default_items);
            $this->assertSame([], $model->default_items->items());
            $this->assertSame('[]', $decoded);
        } finally {
            Json::flushState();
        }
    }

    public function testCollectionCastUsesTheConfiguredEloquentJsonCodec(): void
    {
        $caster = new DataCollectionEloquentCast(CollectionItemData::class);
        $model = new CollectionCastModel;

        try {
            Json::decodeUsing(static fn (): array => [['name' => 'decoded']]);
            Json::encodeUsing(static fn (): string => 'encoded');

            $decoded = $caster->get($model, 'items', 'ignored', []);

            $this->assertEquals(new CollectionItemData('decoded'), $decoded[0]);
            $this->assertSame(
                'encoded',
                $caster->set($model, 'items', [new CollectionItemData('value')], []),
            );
        } finally {
            Json::flushState();
        }
    }

    public function testCollectionCastRejectsAnEncoderFalseResult(): void
    {
        $caster = new DataCollectionEloquentCast(CollectionItemData::class);

        try {
            Json::encodeUsing(static fn (): false => false);

            $this->assertThrows(
                fn () => $caster->set(
                    new CollectionCastModel,
                    'items',
                    [new CollectionItemData('value')],
                    [],
                ),
                JsonEncodingException::class,
                'Unable to encode attribute [items] for model [' . CollectionCastModel::class . ']',
            );
        } finally {
            Json::flushState();
        }
    }

    public function testAbstractCollectionStoresAliasesOrClassNamesAndRoundTripsEncryption(): void
    {
        $this->app->make(DataConfig::class)->enforceMorphMap([
            'first' => CollectionAbstractFirst::class,
        ]);

        $items = [
            new CollectionAbstractFirst('one'),
            new CollectionAbstractSecond('two'),
        ];
        $stored = [
            ['type' => 'first', 'data' => ['name' => 'one']],
            ['type' => CollectionAbstractSecond::class, 'data' => ['name' => 'two']],
        ];
        $model = new CollectionCastModel;
        $model->abstract_items = $items;
        $model->encrypted_abstract_items = $items;

        $this->assertSame($stored, Json::decode($model->getAttributes()['abstract_items']));
        $this->assertSame($stored, Json::decode(Crypt::decryptString($model->getAttributes()['encrypted_abstract_items'])));

        $model = new CollectionCastModel;
        $model->setRawAttributes([
            'abstract_items' => Json::encode($stored),
            'encrypted_abstract_items' => Crypt::encryptString(Json::encode($stored)),
        ]);

        foreach (['abstract_items', 'encrypted_abstract_items'] as $attribute) {
            $this->assertEquals(new CollectionAbstractFirst('one'), $model->{$attribute}[0]);
            $this->assertEquals(new CollectionAbstractSecond('two'), $model->{$attribute}[1]);
        }
    }

    public function testAbstractCollectionRejectsStoredTypesOutsideTheDeclaredClass(): void
    {
        $this->app->make(DataConfig::class)->enforceMorphMap([
            'other' => CollectionOtherData::class,
        ]);
        CollectionUnrelatedFactory::$created = false;

        $caster = new DataCollectionEloquentCast(CollectionAbstractData::class);
        $model = new CollectionCastModel;

        foreach ([
            'missing',
            'other',
            CollectionOtherData::class,
            CollectionAbstractData::class,
            CollectionDto::class,
            CollectionUnrelatedFactory::class,
        ] as $type) {
            $this->assertThrows(
                fn (): ?DataCollection => $caster->get(
                    $model,
                    'abstract_items',
                    json_encode([['type' => $type, 'data' => ['name' => 'value']]], JSON_THROW_ON_ERROR),
                    [],
                ),
                CannotCastData::class,
                'should be a registered alias or a concrete transformable subtype',
            );
        }

        // A stored class outside the declared type is rejected before anything is created from it.
        $this->assertFalse(CollectionUnrelatedFactory::$created);
    }

    public function testCollectionCastRejectsInvalidAssignedAndStoredItems(): void
    {
        $caster = new DataCollectionEloquentCast(CollectionItemData::class);
        $model = new CollectionCastModel;

        foreach ([new stdClass, [new stdClass], [new CollectionDto('value')], [new CollectionOtherData('value')]] as $value) {
            $this->assertThrows(
                fn () => $caster->set($model, 'items', $value, []),
                CannotCastData::class,
            );
        }

        $this->assertThrows(
            fn () => $caster->get($model, 'items', '"value"', []),
            CannotCastData::class,
        );
        $this->assertThrows(
            fn () => $caster->get($model, 'items', '["value"]', []),
            CannotCastData::class,
            'Item `0`',
        );
        $this->assertThrows(
            fn () => $caster->get($model, 'items', '{invalid', []),
            JsonException::class,
        );
    }

    public function testCollectionCastRejectsMissingAndNonTransformableItemClasses(): void
    {
        $this->assertThrows(
            fn () => DataCollection::castUsing([]),
            CannotCastData::class,
            'type of Data should be provided',
        );
        $this->assertThrows(
            fn () => new DataCollectionEloquentCast(CollectionDto::class),
            CannotCastData::class,
            'should implement TransformableData',
        );
    }

    public function testCollectionDirtyComparisonUsesSharedPayloadSemantics(): void
    {
        $model = new CollectionCastModel;
        $model->setRawAttributes([
            'items' => '[{"first":"one","second":"two"}]',
        ], true);
        $model->setRawAttributes([
            'items' => '[{"second":"two","first":"one"}]',
        ]);

        $this->assertFalse($model->isDirty('items'));

        $model->setRawAttributes([
            'items' => '[{"first":"two","second":"one"}]',
        ]);

        $this->assertTrue($model->isDirty('items'));
    }
}

class CollectionCastModel extends Model
{
    /**
     * Get the model's casts.
     */
    protected function casts(): array
    {
        return [
            'items' => DataCollection::class . ':' . CollectionItemData::class,
            'default_items' => DataCollection::class . ':' . CollectionItemData::class . ',default',
            'graph_items' => DataCollection::class . ':' . CollectionGraphItemData::class,
            'override_items' => DataCollection::class . ':' . CollectionOverrideData::class,
            'abstract_items' => DataCollection::class . ':' . CollectionAbstractData::class,
            'encrypted_abstract_items' => DataCollection::class . ':' . CollectionAbstractData::class . ',encrypted',
        ];
    }
}

class CollectionItemData extends Data
{
    public function __construct(public string $name)
    {
    }
}

class CollectionOverrideData extends Data
{
    /** @var list<TransformationContext> */
    public static array $contexts = [];

    public function __construct(public string $name)
    {
    }

    /**
     * Capture each Eloquent item persistence context.
     */
    public function transform(
        TransformationContextFactory|TransformationContext|null $transformationContext = null,
    ): array {
        if ($transformationContext instanceof TransformationContext) {
            self::$contexts[] = $transformationContext;
        }

        return parent::transform($transformationContext);
    }
}

class CollectionInternalOperationData extends Data
{
    public static int $normalizerCalls = 0;

    public function __construct(public string $name)
    {
    }

    /**
     * Get class-owned normalizers.
     */
    public static function normalizers(): array
    {
        ++self::$normalizerCalls;

        return [];
    }

    /**
     * Fail when collection construction reenters the public entry point.
     */
    public static function from(mixed ...$payloads): static
    {
        throw new RuntimeException('Stored collection reads must use the internal item operation.');
    }
}

class CollectionGraphItemData extends Data
{
    #[Computed]
    public string $summary = 'computed';

    public function __construct(
        #[MapOutputName('wire_name')]
        public string $name,
        #[Hidden]
        public string $secret,
        public Lazy $lazy,
    ) {
    }

    /**
     * Get response-only additional data.
     */
    public function with(): array
    {
        return ['response_only' => true];
    }
}

abstract class CollectionAbstractData extends Data
{
    public function __construct(public string $name)
    {
    }
}

class CollectionAbstractFirst extends CollectionAbstractData
{
}

class CollectionAbstractSecond extends CollectionAbstractData
{
}

class CollectionOtherData extends Data
{
    public function __construct(public string $name)
    {
    }
}

class CollectionDto extends Dto
{
    public function __construct(public string $name)
    {
    }
}

class CollectionUnrelatedFactory
{
    public static bool $created = false;

    /**
     * Record that a value was created.
     */
    public static function from(mixed ...$payloads): self
    {
        self::$created = true;

        return new self;
    }
}
