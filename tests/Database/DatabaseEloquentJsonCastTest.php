<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Contracts\Encryption\Encrypter;
use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsCollection;
use Hypervel\Database\Eloquent\Casts\AsDataObject;
use Hypervel\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Hypervel\Database\Eloquent\Casts\AsEncryptedCollection;
use Hypervel\Database\Eloquent\Casts\AsEnumArrayObject;
use Hypervel\Database\Eloquent\Casts\AsEnumCollection;
use Hypervel\Database\Eloquent\Casts\AsFluent;
use Hypervel\Database\Eloquent\Casts\Json;
use Hypervel\Database\Eloquent\JsonEncodingException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\DataObject;
use Hypervel\Support\Facades\Crypt;
use Hypervel\Support\Fluent;
use Hypervel\Testbench\TestCase;
use JsonException;
use Mockery as m;

class DatabaseEloquentJsonCastTest extends TestCase
{
    public function testPrimitiveJsonCastRoundTripsAtTheMaximumNestingDepth(): void
    {
        $value = $this->nestedValue(512);
        $model = new JsonCastModel;

        $model->payload = $value;

        $this->assertSame($value, $model->payload);
    }

    public function testPrimitiveJsonCastRejectsOneLevelOverWithModelContext(): void
    {
        $model = new JsonCastModel;

        $this->assertThrows(
            fn () => $model->payload = $this->nestedValue(513),
            JsonEncodingException::class,
            'Unable to encode attribute [payload] for model [' . JsonCastModel::class . ']',
        );
    }

    public function testDefaultDecoderDistinguishesNullEmptyAndMalformedJson(): void
    {
        $this->assertNull(Json::decode('null'));
        $this->assertNull(Json::decode(''));
        $this->assertThrows(fn () => Json::decode('{invalid'), JsonException::class);
    }

    public function testCustomDecoderReceivesTheStoredEmptyString(): void
    {
        $decoded = null;

        try {
            Json::decodeUsing(function (mixed $value) use (&$decoded): null {
                $decoded = $value;

                return null;
            });

            $this->assertNull(Json::decode(''));
            $this->assertSame('', $decoded);
        } finally {
            Json::flushState();
        }
    }

    public function testEveryFirstPartyJsonClassCastRejectsEncoderFalseWithModelContext(): void
    {
        $model = new JsonCastModel;
        $casters = [
            'array_object' => [AsArrayObject::castUsing([]), ['value']],
            'collection' => [AsCollection::castUsing([]), ['value']],
            'encrypted_array_object' => [AsEncryptedArrayObject::castUsing([]), ['value']],
            'encrypted_collection' => [AsEncryptedCollection::castUsing([]), ['value']],
            'enum_array_object' => [AsEnumArrayObject::castUsing([JsonCastStatus::class]), [JsonCastStatus::Ready]],
            'enum_collection' => [AsEnumCollection::castUsing([JsonCastStatus::class]), [JsonCastStatus::Ready]],
            'fluent' => [AsFluent::castUsing([]), new Fluent(['value' => true])],
            'data_object' => [new AsDataObject(JsonCastData::class), new JsonCastData('value')],
        ];

        try {
            Json::encodeUsing(static fn (): false => false);

            foreach ($casters as $key => [$caster, $value]) {
                try {
                    $caster->set($model, $key, $value, []);
                    $this->fail("The [{$key}] caster accepted an encoder false result.");
                } catch (JsonEncodingException $exception) {
                    $this->assertStringContainsString(
                        "Unable to encode attribute [{$key}] for model [" . JsonCastModel::class . ']',
                        $exception->getMessage(),
                    );
                }
            }
        } finally {
            Json::flushState();
        }
    }

    public function testJsonClassCastReadersRejectSuccessfullyDecodedWrongShapes(): void
    {
        $model = new JsonCastModel;
        $encryptedNull = Crypt::encryptString('null');

        $this->assertNull(AsEncryptedArrayObject::castUsing([])->get($model, 'value', null, ['value' => $encryptedNull]));
        $this->assertNull(AsEncryptedCollection::castUsing([])->get($model, 'value', null, ['value' => $encryptedNull]));
        $this->assertNull((new AsDataObject(JsonCastData::class))->get($model, 'value', 'null', ['value' => 'null']));
        $this->assertNull(AsFluent::castUsing([])->get($model, 'value', 'null', ['value' => 'null']));
    }

    public function testFluentCastAcceptsAnObjectFromACustomDecoder(): void
    {
        try {
            Json::decodeUsing(static fn (): object => (object) ['name' => 'Taylor']);

            $fluent = AsFluent::castUsing([])->get(new JsonCastModel, 'value', '{}', ['value' => '{}']);

            $this->assertInstanceOf(Fluent::class, $fluent);
            $this->assertSame('Taylor', $fluent->name);
        } finally {
            Json::flushState();
        }
    }

    public function testDataObjectCastAcceptsEmptyMapsAndUsesTheCustomCodec(): void
    {
        $caster = new AsDataObject(JsonCastData::class);
        $model = new JsonCastModel;

        $this->assertInstanceOf(JsonCastData::class, $caster->get($model, 'value', '{}', ['value' => '{}']));
        $this->assertInstanceOf(JsonCastData::class, $caster->get($model, 'value', '[]', ['value' => '[]']));

        try {
            Json::decodeUsing(static fn (): array => ['name' => 'decoded']);
            Json::encodeUsing(static fn (): string => 'encoded');

            $this->assertSame('decoded', $caster->get($model, 'value', 'ignored', ['value' => 'ignored'])->name);
            $this->assertSame(['value' => 'encoded'], $caster->set($model, 'value', new JsonCastData('value'), []));
        } finally {
            Json::flushState();
        }
    }

    public function testJsonPathAssignmentRejectsEncoderFalseBeforeStorageOrEncryption(): void
    {
        $model = new JsonCastModel;
        $model->mergeCasts(['encrypted_payload' => 'encrypted:array']);
        $encrypter = m::mock(Encrypter::class);
        $encrypter->expects('encrypt')->never();
        Model::encryptUsing($encrypter);

        try {
            Json::encodeUsing(static fn (): false => false);

            $this->assertThrows(
                fn () => $model->{'payload->key'} = 'value',
                JsonEncodingException::class,
                'Unable to encode attribute [payload]',
            );
            $this->assertThrows(
                fn () => $model->{'encrypted_payload->key'} = 'value',
                JsonEncodingException::class,
                'Unable to encode attribute [encrypted_payload]',
            );
            $this->assertArrayNotHasKey('payload', $model->getAttributes());
            $this->assertArrayNotHasKey('encrypted_payload', $model->getAttributes());
        } finally {
            Json::flushState();
            Model::encryptUsing(null);
        }
    }

    private function nestedValue(int $depth): array
    {
        $value = 'leaf';

        for ($index = 0; $index < $depth; ++$index) {
            $value = ['value' => $value];
        }

        return $value;
    }
}

class JsonCastModel extends Model
{
    protected array $casts = [
        'payload' => 'array',
    ];
}

class JsonCastData extends DataObject
{
    public function __construct(public readonly string $name = 'default')
    {
    }
}

enum JsonCastStatus: string
{
    case Ready = 'ready';
}
