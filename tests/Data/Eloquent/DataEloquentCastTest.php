<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Eloquent\DataEloquentCastTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\DataCollectionOf;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Eloquent\DataEloquentCast;
use Hypervel\Data\Exceptions\CannotCastData;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Database\Eloquent\Casts\Json;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Testbench\TestCase;
use JsonException;
use stdClass;

class DataEloquentCastTest extends TestCase
{
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

    public function testDataCastRoundTripsObjectsArraysNullAndDefaults(): void
    {
        $model = new DataCastModel;
        $model->data = new StoredSimpleData('Taylor');

        $this->assertSame(['name' => 'Taylor'], Json::decode($model->getAttributes()['data']));

        $model = new DataCastModel;
        $model->data = ['name' => 'Abigail'];

        $this->assertSame(['name' => 'Abigail'], Json::decode($model->getAttributes()['data']));

        $model = new DataCastModel;
        $model->setRawAttributes(['data' => '{"name":"Dayle"}']);

        $this->assertEquals(new StoredSimpleData('Dayle'), $model->data);

        $model = new DataCastModel;
        $model->data = null;

        $this->assertNull($model->getAttributes()['data']);
        $this->assertNull($model->data);

        $model = new DataCastModel;
        $model->setRawAttributes([
            'default_data' => null,
            'empty_default_data' => null,
        ]);

        $this->assertEquals(new StoredDefaultData, $model->default_data);
        $this->assertEquals(new StoredEmptyData, $model->empty_default_data);

        foreach (['{}', '[]'] as $stored) {
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

    public function testPropertyMorphableAbstractDataUsesItsOrdinaryPayload(): void
    {
        $caster = new DataEloquentCast(StoredPropertyMorphData::class);
        $model = new DataCastModel;
        $encoded = $caster->set($model, 'property_morph_data', new StoredPropertyMorphFoo('value'), []);

        $this->assertEquals([
            'variant' => 'foo',
            'name' => 'value',
        ], Json::decode($encoded));

        $decoded = $caster->get($model, 'property_morph_data', $encoded, []);

        $this->assertInstanceOf(StoredPropertyMorphFoo::class, $decoded);
        $this->assertSame('value', $decoded->name);
    }

    public function testAbstractDataRequiresAndRoundTripsAnEnforcedAlias(): void
    {
        $this->app->make(DataConfig::class)->enforceMorphMap([
            'first' => StoredAbstractFirst::class,
        ]);

        $caster = new DataEloquentCast(StoredAbstractData::class);
        $model = new DataCastModel;
        $encoded = $caster->set($model, 'abstract_data', new StoredAbstractFirst('value'), []);

        $this->assertSame([
            'type' => 'first',
            'data' => ['name' => 'value'],
        ], Json::decode($encoded));
        $this->assertEquals(
            new StoredAbstractFirst('value'),
            $caster->get($model, 'abstract_data', $encoded, []),
        );
    }

    public function testEncryptedConcreteAndAbstractDataRoundTrip(): void
    {
        $this->app->make(DataConfig::class)->enforceMorphMap([
            'first' => StoredAbstractFirst::class,
        ]);

        $model = new DataCastModel;
        $model->encrypted_data = new StoredSimpleData('concrete');
        $model->encrypted_abstract_data = new StoredAbstractFirst('abstract');

        $encryptedConcrete = $model->getAttributes()['encrypted_data'];
        $encryptedAbstract = $model->getAttributes()['encrypted_abstract_data'];

        $this->assertSame(
            ['name' => 'concrete'],
            Json::decode(Crypt::decryptString($encryptedConcrete)),
        );
        $this->assertSame([
            'type' => 'first',
            'data' => ['name' => 'abstract'],
        ], Json::decode(Crypt::decryptString($encryptedAbstract)));

        $model = new DataCastModel;
        $model->setRawAttributes([
            'encrypted_data' => $encryptedConcrete,
            'encrypted_abstract_data' => $encryptedAbstract,
        ]);

        $this->assertEquals(new StoredSimpleData('concrete'), $model->encrypted_data);
        $this->assertEquals(new StoredAbstractFirst('abstract'), $model->encrypted_abstract_data);
    }

    public function testAbstractDataRejectsValuesWithoutAnEnforcedAlias(): void
    {
        $caster = new DataEloquentCast(StoredAbstractData::class);

        $this->assertThrows(
            fn () => $caster->set(
                new DataCastModel,
                'abstract_data',
                new StoredAbstractFirst('value'),
                [],
            ),
            CannotCastData::class,
            'should have an enforced morph alias',
        );
    }

    public function testAbstractDataRejectsUnknownFqcnAndInvalidMorphClasses(): void
    {
        $config = $this->app->make(DataConfig::class);
        $config->enforceMorphMap([
            'unrelated' => StoredUnrelatedData::class,
            'dto' => StoredDto::class,
        ]);

        $caster = new DataEloquentCast(StoredAbstractData::class);
        $model = new DataCastModel;

        foreach ([
            ['missing', CannotCastData::class],
            [StoredAbstractFirst::class, CannotCastData::class],
            ['unrelated', CannotCastData::class],
            ['dto', CannotCastData::class],
        ] as [$alias, $exception]) {
            $this->assertThrows(
                fn () => $caster->get(
                    $model,
                    'abstract_data',
                    json_encode(['type' => $alias, 'data' => ['name' => 'value']], JSON_THROW_ON_ERROR),
                    [],
                ),
                $exception,
            );
        }
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

    public function testEncryptedDirtyComparisonHonorsPreviousKeys(): void
    {
        $first = Crypt::encryptString('{"name":"Taylor"}');
        $second = Crypt::encryptString('{"name":"Taylor"}');
        $model = new DataCastModel;
        $model->setRawAttributes(['encrypted_data' => $first], true);
        $model->setRawAttributes(['encrypted_data' => $second]);

        $this->assertFalse($model->isDirty('encrypted_data'));

        try {
            Crypt::previousKeys([random_bytes(32)]);

            $this->assertTrue($model->isDirty('encrypted_data'));
        } finally {
            Crypt::previousKeys([]);
        }
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
            'default_data' => StoredDefaultData::class . ':default',
            'empty_default_data' => StoredEmptyData::class . ':default',
            'graph_data' => StoredGraphData::class,
            'pair_data' => StoredPairData::class,
            'abstract_data' => StoredAbstractData::class,
            'encrypted_data' => StoredSimpleData::class . ':encrypted',
            'encrypted_abstract_data' => StoredAbstractData::class . ':encrypted',
            'property_morph_data' => StoredPropertyMorphData::class,
        ];
    }
}

class StoredSimpleData extends Data
{
    public function __construct(public string $name)
    {
    }
}

class StoredDefaultData extends Data
{
    public function __construct(public string $name = 'default')
    {
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

abstract class StoredPropertyMorphData extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public string $variant,
    ) {
    }

    public static function morph(array $properties): ?string
    {
        return match ($properties['variant'] ?? null) {
            'foo' => StoredPropertyMorphFoo::class,
            default => null,
        };
    }
}

class StoredPropertyMorphFoo extends StoredPropertyMorphData
{
    public function __construct(public string $name)
    {
        parent::__construct('foo');
    }
}
