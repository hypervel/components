<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Eloquent\DataEloquentCastTest;

use Hypervel\Contracts\Encryption\DecryptException;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Eloquent\DataEloquentCast;
use Hypervel\Data\Exceptions\CannotCastData;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Data\Support\Transformation\TransformationContext;
use Hypervel\Data\Support\Transformation\TransformationContextFactory;
use Hypervel\Database\Eloquent\Casts\Json;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use Hypervel\Tests\Data\Fixtures\AbstractData\AbstractDataA;
use Hypervel\Tests\Data\Fixtures\AbstractData\AbstractDataB;
use Hypervel\Tests\Data\Fixtures\Enums\DummyBackedEnum;
use Hypervel\Tests\Data\Fixtures\LazyData;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithCasts;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithDefaultCasts;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithEncryptedCasts;
use Hypervel\Tests\Data\Fixtures\Models\DummyModelWithJson;
use Hypervel\Tests\Data\Fixtures\SimpleData;
use Hypervel\Tests\Data\Fixtures\SimpleDataWithDefaultValue;
use JsonException;
use stdClass;

class DataEloquentCastTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    /**
     * Get package providers for the data cast test application.
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

    public function testCanSaveADataObject(): void
    {
        DummyModelWithCasts::create([
            'data' => new SimpleData('Test'),
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data' => json_encode(['string' => 'Test']),
        ]);
    }

    public function testCanSaveADataObjectAsAnArray(): void
    {
        DummyModelWithCasts::create([
            'data' => ['string' => 'Test'],
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data' => json_encode(['string' => 'Test']),
        ]);
    }

    public function testCanLoadADataObject(): void
    {
        DB::table('dummy_model_with_casts')->insert([
            'data' => json_encode(['string' => 'Test']),
        ]);

        $this->assertEquals(new SimpleData('Test'), DummyModelWithCasts::first()->data);
    }

    public function testCanSaveANullAsAValue(): void
    {
        DummyModelWithCasts::create([
            'data' => null,
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data' => null,
        ]);
    }

    public function testCanLoadNullAsAValue(): void
    {
        DB::table('dummy_model_with_casts')->insert([
            'data' => null,
        ]);

        $this->assertNull(DummyModelWithCasts::first()->data);
    }

    public function testLoadsACastObjectWhenNullableArgumentUsedAndValueIsNullInDatabase(): void
    {
        DB::table('dummy_model_with_casts')->insert([
            'data' => null,
        ]);

        $data = DummyModelWithDefaultCasts::first()->data;

        $this->assertInstanceOf(SimpleDataWithDefaultValue::class, $data);
        $this->assertSame('default', $data->string);
    }

    public function testCanUseAnAbstractDataClassWithMultipleChildren(): void
    {
        $abstractA = new AbstractDataA('A\A');
        $abstractB = new AbstractDataB('B\B');

        $modelId = DummyModelWithCasts::create([
            'abstract_data' => $abstractA,
        ])->id;

        $model = DummyModelWithCasts::find($modelId);

        $this->assertInstanceOf(AbstractDataA::class, $model->abstract_data);
        $this->assertSame('A\A', $model->abstract_data->a);

        $model->abstract_data = $abstractB;
        $model->save();

        $model = DummyModelWithCasts::find($modelId);

        $this->assertInstanceOf(AbstractDataB::class, $model->abstract_data);
        $this->assertSame('B\B', $model->abstract_data->b);
    }

    public function testCanUseAnAbstractDataClassWithMorphMap(): void
    {
        $this->app->make(DataConfig::class)->enforceMorphMap([
            'a' => AbstractDataA::class,
        ]);

        $abstractA = new AbstractDataA('A\A');
        $abstractB = new AbstractDataB('B\B');

        $modelA = DummyModelWithCasts::create([
            'abstract_data' => $abstractA,
        ]);

        $modelB = DummyModelWithCasts::create([
            'abstract_data' => $abstractB,
        ]);

        $this->assertSame(['type' => 'a', 'data' => ['a' => 'A\A']], Json::decode($modelA->getRawOriginal('abstract_data')));
        $this->assertSame(
            ['type' => AbstractDataB::class, 'data' => ['b' => 'B\B']],
            Json::decode($modelB->getRawOriginal('abstract_data')),
        );

        $loadedMorphedModel = DummyModelWithCasts::find($modelA->id);

        $this->assertInstanceOf(AbstractDataA::class, $loadedMorphedModel->abstract_data);
        $this->assertSame('A\A', $loadedMorphedModel->abstract_data->a);
    }

    public function testCanSaveAnEncryptedDataObject(): void
    {
        $model = DummyModelWithEncryptedCasts::create([
            'data' => new SimpleData('Test'),
        ]);

        try {
            $decrypted = Crypt::decryptString($model->getRawOriginal('data'));
        } catch (DecryptException) {
            $this->fail('Expected the stored value to be encrypted.');
        }

        $this->assertSame(['string' => 'Test'], Json::decode($decrypted));
    }

    public function testCanLoadAnEncryptedDataObject(): void
    {
        DummyModelWithEncryptedCasts::create([
            'data' => new SimpleData('Test'),
        ]);

        $this->assertEquals(new SimpleData('Test'), DummyModelWithEncryptedCasts::first()->data);
    }

    public function testCanLoadAndSaveAnAbstractDefinedDataObject(): void
    {
        $abstractA = new AbstractDataA('A\A');

        $modelId = DummyModelWithEncryptedCasts::create([
            'abstract_data' => $abstractA,
        ])->id;

        $model = DummyModelWithEncryptedCasts::find($modelId);

        $this->assertInstanceOf(AbstractDataA::class, $model->abstract_data);
        $this->assertSame('A\A', $model->abstract_data->a);

        try {
            $decrypted = Crypt::decryptString($model->getRawOriginal('abstract_data'));
        } catch (DecryptException) {
            $this->fail('Expected the stored value to be encrypted.');
        }

        $this->assertSame(['type' => AbstractDataA::class, 'data' => ['a' => 'A\A']], Json::decode($decrypted));
    }

    public function testCanLoadAndSaveAnAbstractPropertyMorphableDataObject(): void
    {
        $modelClass = new class extends Model {
            protected array $guarded = [];

            protected array $casts = [
                'data' => TestCastAbstractPropertyMorphableData::class,
            ];

            protected ?string $table = 'dummy_model_with_casts';

            public bool $timestamps = false;
        };

        $abstractA = new TestCastPropertyMorphableDataFoo('foo');

        $modelId = $modelClass::create([
            'data' => $abstractA,
        ])->id;

        $this->assertDatabaseHas($modelClass::class, [
            'data' => json_encode(['a' => 'foo', 'variant' => 'foo']),
        ]);

        $model = $modelClass::find($modelId);

        $this->assertInstanceOf(TestCastPropertyMorphableDataFoo::class, $model->data);
        $this->assertSame('foo', $model->data->a);
        $this->assertSame(DummyBackedEnum::FOO, $model->data->variant);
    }

    public function testCanCorrectlyDetectIfTheAttributeIsDirty(): void
    {
        $model = new DummyModelWithJson;
        // Set raw because we want to inverse the order of the keys
        $model->setRawAttributes(['data' => json_encode(['second' => 'Second', 'first' => 'First'])]);
        $model->save();

        $model->setRawAttributes(['data' => json_encode(['first' => 'First', 'second' => 'Second'])]);

        $this->assertSame('{"second":"Second","first":"First"}', $model->getRawOriginal('data'));
        $this->assertSame('{"first":"First","second":"Second"}', $model->getAttributes()['data']);
        $this->assertFalse($model->isDirty('data'));

        $model->data->first = 'First2';

        $this->assertTrue($model->isDirty('data'));
    }

    public function testCanCorrectlyDetectIfTheAttributeIsDirtyWithNullValues(): void
    {
        $model = new DummyModelWithJson;
        $model->save();

        $model->setRawAttributes(['data' => json_encode(['first' => 'First', 'second' => 'Second'])]);

        $this->assertNull($model->getRawOriginal('data'));
        $this->assertSame('{"first":"First","second":"Second"}', $model->getAttributes()['data']);
        $this->assertTrue($model->isDirty('data'));
    }

    public function testFlagsTheAttributeAsDirtyWhenItIsEncryptedAndThereArePreviousEncryptionKeys(): void
    {
        // The encrypter reads app.previous_keys when it is created, so the key is added to it directly.
        try {
            Crypt::previousKeys([random_bytes(32)]);

            $model = new DummyModelWithEncryptedCasts;
            $model->data = new SimpleData('First');
            $model->save();

            $model->data = $model->data;

            $this->assertNotSame($model->getAttributes()['data'], $model->getRawOriginal('data'));
            $this->assertTrue($model->isDirty('data'));
        } finally {
            Crypt::previousKeys([]);
        }
    }

    public function testDoesNotFlagTheAttributeAsDirtyWhenItIsEncryptedAndThereAreNoPreviousEncryptionKeys(): void
    {
        $model = new DummyModelWithEncryptedCasts;
        $model->data = new SimpleData('First');
        $model->save();

        $model->data = $model->data;

        $this->assertNotSame($model->getAttributes()['data'], $model->getRawOriginal('data'));
        $this->assertFalse($model->isDirty('data'));
    }

    public function testCanUpdateAModelWhereTheCastIsInitiallyNull(): void
    {
        $model = DummyModelWithCasts::create([
            'data' => null,
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data' => null,
        ]);

        $model->update([
            'data' => new SimpleData('Test'),
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data' => json_encode(['string' => 'Test']),
        ]);
    }

    public function testCanSaveADataObjectWithLazyPropertiesWhichGetResolved(): void
    {
        DummyModelWithCasts::create([
            'lazy_data' => LazyData::fromString('Test'),
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'lazy_data' => json_encode(['name' => 'Test']),
        ]);
    }

    public function testCanUpdateAModelWhereTheCastIsInitiallyNotNull(): void
    {
        $model = DummyModelWithCasts::create([
            'data' => new SimpleData('Test'),
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data' => json_encode(['string' => 'Test']),
        ]);

        $model->update([
            'data' => null,
        ]);

        $this->assertDatabaseHas(DummyModelWithCasts::class, [
            'data' => null,
        ]);
    }

    public function testDefaultDataReadsEmptyStoredObjectsAndLists(): void
    {
        foreach ([null, '{}', '[]'] as $stored) {
            $model = new DataCastModel;
            $model->setRawAttributes(['empty_default_data' => $stored]);

            $this->assertEquals(new StoredEmptyData, $model->empty_default_data);
        }
    }

    public function testDataCastPersistsTheCompleteConstructableViewWithoutMutatingPartials(): void
    {
        $nested = (new StoredNestedData('nested'))->only('value');
        $item = (new StoredNestedData('item'))->except('value');
        $items = (new DataCollection(StoredNestedData::class, [$item]))->only('value');
        $data = (new StoredGraphData(
            name: 'Taylor',
            secret: 'private',
            nested: $nested,
            items: $items,
            lazy: Lazy::create(static fn (): string => 'resolved'),
        ))->exclude('name')->additional(['response_only' => true]);

        $rootPartials = $data->getPartialsDefinition()->resolve($data);
        $nestedPartials = $nested->getPartialsDefinition()->resolve($nested);
        $collectionPartials = $items->getPartialsDefinition()->resolve($items);
        $itemPartials = $item->getPartialsDefinition()->resolve($item);

        $model = new DataCastModel;
        $model->graph_data = $data;

        $this->assertSame([
            'name' => 'Taylor',
            'secret' => 'private',
            'nested' => ['value' => 'nested'],
            'items' => [['value' => 'item']],
            'lazy' => 'resolved',
        ], Json::decode($model->getAttributes()['graph_data']));
        $this->assertSame($rootPartials, $data->getPartialsDefinition()->resolve($data));
        $this->assertSame($nestedPartials, $nested->getPartialsDefinition()->resolve($nested));
        $this->assertSame($collectionPartials, $items->getPartialsDefinition()->resolve($items));
        $this->assertSame($itemPartials, $item->getPartialsDefinition()->resolve($item));
    }

    public function testDataCastUsesTheInstanceTransformationBoundary(): void
    {
        StoredOverrideData::$context = null;
        $model = new DataCastModel;
        $model->override_data = new StoredOverrideData('Taylor');

        $this->assertSame(['name' => 'Taylor'], Json::decode($model->getAttributes()['override_data']));
        $this->assertInstanceOf(TransformationContext::class, StoredOverrideData::$context);
        $this->assertTrue(StoredOverrideData::$context->constructable);
        $this->assertFalse(StoredOverrideData::$context->mapPropertyNames);
    }

    public function testDataCastUsesTheConfiguredEloquentJsonCodec(): void
    {
        $caster = new DataEloquentCast(StoredSimpleData::class);
        $model = new DataCastModel;

        try {
            Json::decodeUsing(static fn (): array => ['name' => 'decoded']);
            Json::encodeUsing(static fn (): string => 'encoded');

            $this->assertEquals(
                new StoredSimpleData('decoded'),
                $caster->get($model, 'data', 'ignored', []),
            );
            $this->assertSame(
                'encoded',
                $caster->set($model, 'data', new StoredSimpleData('value'), []),
            );
        } finally {
            Json::flushState();
        }
    }

    public function testDataCastRejectsAnEncoderFalseResult(): void
    {
        $caster = new DataEloquentCast(StoredSimpleData::class);

        try {
            Json::encodeUsing(static fn (): false => false);

            $this->assertThrows(
                fn () => $caster->set(
                    new DataCastModel,
                    'data',
                    new StoredSimpleData('value'),
                    [],
                ),
                JsonEncodingException::class,
                'Unable to encode attribute [data] for model [' . DataCastModel::class . ']',
            );
        } finally {
            Json::flushState();
        }
    }

    public function testAbstractDataRejectsStoredTypesOutsideTheDeclaredClass(): void
    {
        $this->app->make(DataConfig::class)->enforceMorphMap([
            'unrelated' => StoredUnrelatedData::class,
            'dto' => StoredDto::class,
        ]);
        StoredUnrelatedFactory::$created = false;

        $caster = new DataEloquentCast(StoredAbstractData::class);
        $model = new DataCastModel;

        foreach ([
            'missing',
            'Missing\Data\Class',
            'unrelated',
            StoredUnrelatedData::class,
            'dto',
            StoredAbstractData::class,
            StoredUnrelatedFactory::class,
        ] as $type) {
            $this->assertThrows(
                fn (): ?BaseData => $caster->get(
                    $model,
                    'abstract_data',
                    json_encode(['type' => $type, 'data' => ['name' => 'value']], JSON_THROW_ON_ERROR),
                    [],
                ),
                CannotCastData::class,
                'should be a registered alias or a concrete transformable subtype',
            );
        }

        // A stored class outside the declared type is rejected before anything is created from it.
        $this->assertFalse(StoredUnrelatedFactory::$created);
        $this->assertEquals(
            new StoredAbstractFirst('value'),
            $caster->get(
                $model,
                'abstract_data',
                json_encode(['type' => StoredAbstractFirst::class, 'data' => ['name' => 'value']], JSON_THROW_ON_ERROR),
                [],
            ),
        );
    }

    public function testDataCastRejectsInvalidAssignedValues(): void
    {
        $caster = new DataEloquentCast(StoredSimpleData::class);
        $model = new DataCastModel;

        foreach ([new stdClass, new StoredDto('value'), new StoredUnrelatedData('value')] as $value) {
            $this->assertThrows(
                fn () => $caster->set($model, 'data', $value, []),
                CannotCastData::class,
            );
        }
    }

    public function testDataCastRejectsANonTransformableTargetClass(): void
    {
        $this->assertThrows(
            fn () => new DataEloquentCast(StoredDto::class),
            CannotCastData::class,
            'should implement TransformableData',
        );
    }

    public function testDataCastRejectsMalformedAndScalarStoredJson(): void
    {
        $caster = new DataEloquentCast(StoredSimpleData::class);
        $model = new DataCastModel;

        $this->assertThrows(
            fn () => $caster->get($model, 'data', '{invalid', []),
            JsonException::class,
        );
        $this->assertThrows(
            fn () => $caster->get($model, 'data', '"value"', []),
            CannotCastData::class,
        );
    }

    public function testDirtyComparisonIgnoresJsonObjectKeyOrderRecursively(): void
    {
        $this->assertDirtyComparison(
            ['first' => 'one', 'second' => 'two'],
            ['second' => 'two', 'first' => 'one'],
            false,
        );
        $this->assertDirtyComparison(
            ['meta' => ['first' => 'one', 'second' => 'two']],
            ['meta' => ['second' => 'two', 'first' => 'one']],
            false,
        );
        $this->assertDirtyComparison(
            [2 => 'two', 1 => 'one'],
            [1 => 'one', 2 => 'two'],
            false,
        );
    }

    public function testDirtyComparisonPreservesListOrderAndStrictLeafTypes(): void
    {
        $this->assertDirtyComparison(['items' => ['one', 'two']], ['items' => ['two', 'one']], true);
        $this->assertDirtyComparison(['value' => 1], ['value' => '1'], true);
        $this->assertDirtyComparison(['value' => 'one'], ['value' => 'two'], true);
    }

    public function testDirtyComparisonHandlesNullAndDefaultValues(): void
    {
        $model = new DataCastModel;
        $model->setRawAttributes(['data' => null], true);
        $model->setRawAttributes(['data' => '{}']);

        $this->assertTrue($model->isDirty('data'));

        $model = new DataCastModel;
        $model->setRawAttributes(['empty_default_data' => null], true);
        $model->setRawAttributes(['empty_default_data' => '{}']);

        $this->assertFalse($model->isDirty('empty_default_data'));
    }

    /**
     * Assert dirty comparison through Eloquent's real class-cast caller.
     */
    private function assertDirtyComparison(array $original, array $current, bool $dirty): void
    {
        $model = new DataCastModel;
        $model->setRawAttributes([
            'pair_data' => json_encode($original, JSON_THROW_ON_ERROR),
        ], true);
        $model->setRawAttributes([
            'pair_data' => json_encode($current, JSON_THROW_ON_ERROR),
        ]);

        $this->assertSame($dirty, $model->isDirty('pair_data'));
    }
}

class DataCastModel extends Model
{
    /**
     * Get the model's casts.
     */
    protected function casts(): array
    {
        return [
            'data' => StoredSimpleData::class,
            'empty_default_data' => StoredEmptyData::class . ':default',
            'graph_data' => StoredGraphData::class,
            'pair_data' => StoredPairData::class,
            'override_data' => StoredOverrideData::class,
        ];
    }
}

class StoredSimpleData extends Data
{
    public function __construct(public string $name)
    {
    }
}

class StoredOverrideData extends Data
{
    public static ?TransformationContext $context = null;

    public function __construct(public string $name)
    {
    }

    /**
     * Capture the Eloquent persistence context.
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

class StoredEmptyData extends Data
{
}

class StoredNestedData extends Data
{
    public function __construct(public string $value)
    {
    }
}

class StoredGraphData extends Data
{
    #[Computed]
    public string $summary = 'computed';

    public function __construct(
        #[MapOutputName('wire_name')]
        public string $name,
        #[Hidden]
        public string $secret,
        public StoredNestedData $nested,
        #[DataCollectionOf(StoredNestedData::class)]
        public DataCollection $items,
        public Lazy $lazy,
    ) {
    }

    /**
     * Get response-only additional data.
     */
    public function with(): array
    {
        return ['class_response_only' => true];
    }
}

class StoredPairData extends Data
{
    public function __construct(
        public mixed $first = null,
        public mixed $second = null,
        public mixed $meta = null,
        public mixed $items = null,
        public mixed $value = null,
    ) {
    }
}

abstract class StoredAbstractData extends Data
{
    public function __construct(public string $name)
    {
    }
}

class StoredAbstractFirst extends StoredAbstractData
{
}

class StoredUnrelatedData extends Data
{
    public function __construct(public string $name)
    {
    }
}

class StoredDto extends Dto
{
    public function __construct(public string $name)
    {
    }
}

class StoredUnrelatedFactory
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

abstract class TestCastAbstractPropertyMorphableData extends Data implements PropertyMorphableData
{
    /**
     * Create a property-morphable fixture.
     */
    public function __construct(
        #[PropertyForMorph]
        public DummyBackedEnum $variant
    ) {
    }

    /**
     * Get the subclass for the variant.
     */
    public static function morph(array $properties): ?string
    {
        return match ($properties['variant'] ?? null) {
            DummyBackedEnum::FOO => TestCastPropertyMorphableDataFoo::class,
            default => null,
        };
    }
}

class TestCastPropertyMorphableDataFoo extends TestCastAbstractPropertyMorphableData
{
    /**
     * Create the foo variant.
     */
    public function __construct(public string $a)
    {
        parent::__construct(DummyBackedEnum::FOO);
    }
}
