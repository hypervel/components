<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Eloquent\DataCollectionEloquentCastTest;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Data\Attributes\Computed;
use Hypervel\Data\Attributes\Hidden;
use Hypervel\Data\Attributes\MapOutputName;
use Hypervel\Data\Attributes\PropertyForMorph;
use Hypervel\Data\Contracts\PropertyMorphableData;
use Hypervel\Data\Data;
use Hypervel\Data\DataCollection;
use Hypervel\Data\DataServiceProvider;
use Hypervel\Data\Dto;
use Hypervel\Data\Eloquent\DataCollectionEloquentCast;
use Hypervel\Data\Exceptions\CannotCastData;
use Hypervel\Data\Lazy;
use Hypervel\Data\Support\DataConfig;
use Hypervel\Database\Eloquent\Casts\Json;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Testbench\TestCase;
use JsonException;
use RuntimeException;
use stdClass;

class DataCollectionEloquentCastTest extends TestCase
{
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

    public function testCollectionCastRoundTripsCollectionsArraysKeysAndNull(): void
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
        $model->items = [
            ['name' => 'Taylor'],
            ['name' => 'Abigail'],
        ];

        $this->assertSame([
            ['name' => 'Taylor'],
            ['name' => 'Abigail'],
        ], Json::decode($model->getAttributes()['items']));

        $model = new CollectionCastModel;
        $model->setRawAttributes([
            'items' => '{"first":{"name":"Taylor"},"second":{"name":"Abigail"}}',
        ]);

        $this->assertInstanceOf(DataCollection::class, $model->items);
        $this->assertSame(['first', 'second'], array_keys($model->items->items()));
        $this->assertEquals(new CollectionItemData('Taylor'), $model->items['first']);
        $this->assertEquals(new CollectionItemData('Abigail'), $model->items['second']);

        $model = new CollectionCastModel;
        $model->items = null;

        $this->assertNull($model->getAttributes()['items']);
        $this->assertNull($model->items);
    }

    public function testCollectionCastReturnsTheDeclaredCollectionSubclass(): void
    {
        $model = new CollectionCastModel;
        $model->setRawAttributes(['custom_items' => '[{"name":"Taylor"}]']);

        $this->assertInstanceOf(CustomDataCollection::class, $model->custom_items);
        $this->assertEquals(new CollectionItemData('Taylor'), $model->custom_items[0]);
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

    public function testPropertyMorphableCollectionUsesOrdinaryItemPayloads(): void
    {
        $caster = new DataCollectionEloquentCast(CollectionPropertyMorphData::class);
        $model = new CollectionCastModel;
        $encoded = $caster->set($model, 'property_morph_items', [
            new CollectionPropertyMorphFoo('first'),
            new CollectionPropertyMorphBar('second'),
        ], []);

        $this->assertEquals([
            ['name' => 'first', 'variant' => 'foo'],
            ['name' => 'second', 'variant' => 'bar'],
        ], Json::decode($encoded));

        $decoded = $caster->get($model, 'property_morph_items', $encoded, []);

        $this->assertInstanceOf(CollectionPropertyMorphFoo::class, $decoded[0]);
        $this->assertInstanceOf(CollectionPropertyMorphBar::class, $decoded[1]);
    }

    public function testAbstractCollectionRoundTripsEnforcedAliasesAndEncryption(): void
    {
        $this->app->make(DataConfig::class)->enforceMorphMap([
            'first' => CollectionAbstractFirst::class,
            'second' => CollectionAbstractSecond::class,
        ]);

        $items = [
            new CollectionAbstractFirst('one'),
            new CollectionAbstractSecond('two'),
        ];
        $model = new CollectionCastModel;
        $model->abstract_items = $items;

        $this->assertSame([
            ['type' => 'first', 'data' => ['name' => 'one']],
            ['type' => 'second', 'data' => ['name' => 'two']],
        ], Json::decode($model->getAttributes()['abstract_items']));

        $model = new CollectionCastModel;
        $model->setRawAttributes(['abstract_items' => Json::encode([
            ['type' => 'first', 'data' => ['name' => 'one']],
            ['type' => 'second', 'data' => ['name' => 'two']],
        ])]);

        $this->assertInstanceOf(CollectionAbstractFirst::class, $model->abstract_items[0]);
        $this->assertInstanceOf(CollectionAbstractSecond::class, $model->abstract_items[1]);

        $model = new CollectionCastModel;
        $model->encrypted_items = [new CollectionItemData('concrete')];
        $model->encrypted_abstract_items = $items;
        $encryptedConcrete = $model->getAttributes()['encrypted_items'];
        $encrypted = $model->getAttributes()['encrypted_abstract_items'];

        $this->assertSame(
            [['name' => 'concrete']],
            Json::decode(Crypt::decryptString($encryptedConcrete)),
        );
        $this->assertNotSame('[', $encrypted[0]);
        $this->assertCount(2, Json::decode(Crypt::decryptString($encrypted)));

        $model = new CollectionCastModel;
        $model->setRawAttributes([
            'encrypted_items' => $encryptedConcrete,
            'encrypted_abstract_items' => $encrypted,
        ]);

        $this->assertEquals(new CollectionItemData('concrete'), $model->encrypted_items[0]);
        $this->assertInstanceOf(CollectionAbstractFirst::class, $model->encrypted_abstract_items[0]);
        $this->assertInstanceOf(CollectionAbstractSecond::class, $model->encrypted_abstract_items[1]);
    }

    public function testAbstractCollectionRejectsMissingAndUnknownAliases(): void
    {
        $caster = new DataCollectionEloquentCast(CollectionAbstractData::class);
        $model = new CollectionCastModel;

        $this->assertThrows(
            fn () => $caster->set(
                $model,
                'abstract_items',
                [new CollectionAbstractFirst('value')],
                [],
            ),
            CannotCastData::class,
            'should have an enforced morph alias',
        );
        $this->assertThrows(
            fn () => $caster->get(
                $model,
                'abstract_items',
                '[{"type":"missing","data":{"name":"value"}}]',
                [],
            ),
            CannotCastData::class,
            'is not registered',
        );
        $this->assertThrows(
            fn () => $caster->get(
                $model,
                'abstract_items',
                json_encode([
                    ['type' => CollectionAbstractFirst::class, 'data' => ['name' => 'value']],
                ], JSON_THROW_ON_ERROR),
                [],
            ),
            CannotCastData::class,
            'is not registered',
        );
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

    public function testEncryptedCollectionDirtyComparisonHonorsPreviousKeys(): void
    {
        $first = Crypt::encryptString('[{"name":"Taylor"}]');
        $second = Crypt::encryptString('[{"name":"Taylor"}]');
        $model = new CollectionCastModel;
        $model->setRawAttributes(['encrypted_items' => $first], true);
        $model->setRawAttributes(['encrypted_items' => $second]);

        $this->assertFalse($model->isDirty('encrypted_items'));

        try {
            Crypt::previousKeys([random_bytes(32)]);

            $this->assertTrue($model->isDirty('encrypted_items'));
        } finally {
            Crypt::previousKeys([]);
        }
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
            'custom_items' => CustomDataCollection::class . ':' . CollectionItemData::class,
            'graph_items' => DataCollection::class . ':' . CollectionGraphItemData::class,
            'abstract_items' => DataCollection::class . ':' . CollectionAbstractData::class,
            'encrypted_items' => DataCollection::class . ':' . CollectionItemData::class . ',encrypted',
            'encrypted_abstract_items' => DataCollection::class . ':' . CollectionAbstractData::class . ',encrypted',
            'property_morph_items' => DataCollection::class . ':' . CollectionPropertyMorphData::class,
        ];
    }
}

class CollectionItemData extends Data
{
    public function __construct(public string $name)
    {
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

/**
 * @extends DataCollection<array-key, CollectionItemData>
 */
class CustomDataCollection extends DataCollection
{
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

abstract class CollectionPropertyMorphData extends Data implements PropertyMorphableData
{
    public function __construct(
        #[PropertyForMorph]
        public string $variant,
    ) {
    }

    public static function morph(array $properties): ?string
    {
        return match ($properties['variant'] ?? null) {
            'foo' => CollectionPropertyMorphFoo::class,
            'bar' => CollectionPropertyMorphBar::class,
            default => null,
        };
    }
}

class CollectionPropertyMorphFoo extends CollectionPropertyMorphData
{
    public function __construct(public string $name)
    {
        parent::__construct('foo');
    }
}

class CollectionPropertyMorphBar extends CollectionPropertyMorphData
{
    public function __construct(public string $name)
    {
        parent::__construct('bar');
    }
}
