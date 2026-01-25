<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Integration\Eloquent;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Collection;
use Hypervel\Tests\Database\Integration\IntegrationTestCase;

/**
 * @internal
 * @coversNothing
 * @group integration
 * @group pgsql-integration
 */
class CastsTest extends IntegrationTestCase
{
    public function testIntegerCast(): void
    {
        $model = CastModel::create(['name' => 'Test', 'age' => '25']);

        $this->assertIsInt($model->age);
        $this->assertSame(25, $model->age);

        $retrieved = CastModel::find($model->id);
        $this->assertIsInt($retrieved->age);
    }

    public function testFloatCast(): void
    {
        $model = CastModel::create(['name' => 'Test', 'price' => '19.99']);

        $this->assertIsFloat($model->price);
        $this->assertSame(19.99, $model->price);
    }

    public function testBooleanCast(): void
    {
        $model = CastModel::create(['name' => 'Test', 'is_active' => 1]);

        $this->assertIsBool($model->is_active);
        $this->assertTrue($model->is_active);

        $model->is_active = 0;
        $model->save();

        $this->assertFalse($model->fresh()->is_active);
    }

    public function testArrayCast(): void
    {
        $metadata = ['key' => 'value', 'nested' => ['a' => 1, 'b' => 2]];
        $model = CastModel::create(['name' => 'Test', 'metadata' => $metadata]);

        $this->assertIsArray($model->metadata);
        $this->assertSame('value', $model->metadata['key']);
        $this->assertSame(1, $model->metadata['nested']['a']);

        $retrieved = CastModel::find($model->id);
        $this->assertIsArray($retrieved->metadata);
        $this->assertSame($metadata, $retrieved->metadata);
    }

    public function testJsonCastWithNull(): void
    {
        $model = CastModel::create(['name' => 'Test', 'metadata' => null]);

        $this->assertNull($model->metadata);

        $retrieved = CastModel::find($model->id);
        $this->assertNull($retrieved->metadata);
    }

    public function testCollectionCast(): void
    {
        $tags = ['php', 'laravel', 'hypervel'];
        $model = CastModel::create(['name' => 'Test', 'tags' => $tags]);

        $this->assertInstanceOf(Collection::class, $model->tags);
        $this->assertCount(3, $model->tags);
        $this->assertContains('php', $model->tags->toArray());

        $retrieved = CastModel::find($model->id);
        $this->assertInstanceOf(Collection::class, $retrieved->tags);
    }

    public function testDatetimeCast(): void
    {
        $now = Carbon::now();
        $model = CastModel::create(['name' => 'Test', 'published_at' => $now]);

        $this->assertInstanceOf(CarbonInterface::class, $model->published_at);

        $retrieved = CastModel::find($model->id);
        $this->assertInstanceOf(CarbonInterface::class, $retrieved->published_at);
        $this->assertSame($now->format('Y-m-d H:i:s'), $retrieved->published_at->format('Y-m-d H:i:s'));
    }

    public function testDateCast(): void
    {
        $date = Carbon::parse('1990-05-15');
        $model = CastModel::create(['name' => 'Test', 'birth_date' => $date]);

        $this->assertInstanceOf(CarbonInterface::class, $model->birth_date);

        $retrieved = CastModel::find($model->id);
        $this->assertSame('1990-05-15', $retrieved->birth_date->format('Y-m-d'));
    }

    public function testDatetimeCastFromString(): void
    {
        $model = CastModel::create(['name' => 'Test', 'published_at' => '2024-01-15 10:30:00']);

        $this->assertInstanceOf(CarbonInterface::class, $model->published_at);
        $this->assertSame('2024-01-15', $model->published_at->format('Y-m-d'));
        $this->assertSame('10:30:00', $model->published_at->format('H:i:s'));
    }

    public function testTimestampsCast(): void
    {
        $model = CastModel::create(['name' => 'Test']);

        $this->assertInstanceOf(CarbonInterface::class, $model->created_at);
        $this->assertInstanceOf(CarbonInterface::class, $model->updated_at);
    }

    public function testEnumCast(): void
    {
        $model = CastModel::create(['name' => 'Test', 'status' => CastStatus::Active]);

        $this->assertInstanceOf(CastStatus::class, $model->status);
        $this->assertSame(CastStatus::Active, $model->status);

        $retrieved = CastModel::find($model->id);
        $this->assertInstanceOf(CastStatus::class, $retrieved->status);
        $this->assertSame(CastStatus::Active, $retrieved->status);
    }

    public function testEnumCastFromString(): void
    {
        $model = CastModel::create(['name' => 'Test', 'status' => 'pending']);

        $this->assertInstanceOf(CastStatus::class, $model->status);
        $this->assertSame(CastStatus::Pending, $model->status);
    }

    public function testCastOnUpdate(): void
    {
        $model = CastModel::create(['name' => 'Test', 'age' => 25]);

        $model->update(['age' => '30']);

        $this->assertIsInt($model->age);
        $this->assertSame(30, $model->age);
    }

    public function testMassAssignmentWithCasts(): void
    {
        $model = CastModel::create([
            'name' => 'Test',
            'age' => '25',
            'price' => '99.99',
            'is_active' => '1',
            'metadata' => ['foo' => 'bar'],
            'published_at' => '2024-01-01 00:00:00',
        ]);

        $this->assertIsInt($model->age);
        $this->assertIsFloat($model->price);
        $this->assertIsBool($model->is_active);
        $this->assertIsArray($model->metadata);
        $this->assertInstanceOf(CarbonInterface::class, $model->published_at);
    }

    public function testArrayObjectCast(): void
    {
        $settings = ['theme' => 'dark', 'notifications' => true];
        $model = CastModel::create(['name' => 'Test', 'settings' => $settings]);

        $this->assertInstanceOf(\ArrayObject::class, $model->settings);
        $this->assertSame('dark', $model->settings['theme']);

        $model->settings['theme'] = 'light';
        $model->save();

        $retrieved = CastModel::find($model->id);
        $this->assertSame('light', $retrieved->settings['theme']);
    }

    public function testNullableAttributesWithCasts(): void
    {
        $model = CastModel::create(['name' => 'Test']);

        $this->assertNull($model->age);
        $this->assertNull($model->price);
        $this->assertNull($model->metadata);
        $this->assertNull($model->published_at);
    }

    public function testGetOriginalWithCasts(): void
    {
        $model = CastModel::create(['name' => 'Test', 'age' => 25]);

        $model->age = 30;

        $this->assertSame(30, $model->age);
        $this->assertSame(25, $model->getOriginal('age'));
    }

    public function testIsDirtyWithCasts(): void
    {
        $model = CastModel::create(['name' => 'Test', 'age' => 25]);

        $this->assertFalse($model->isDirty('age'));

        $model->age = 30;

        $this->assertTrue($model->isDirty('age'));
    }

    public function testWasChangedWithCasts(): void
    {
        $model = CastModel::create(['name' => 'Test', 'age' => 25]);

        $model->age = 30;
        $model->save();

        $this->assertTrue($model->wasChanged('age'));
        $this->assertFalse($model->wasChanged('name'));
    }
}

enum CastStatus: string
{
    case Active = 'active';
    case Inactive = 'inactive';
    case Pending = 'pending';
}

class CastModel extends Model
{
    protected ?string $table = 'cast_models';

    protected array $fillable = [
        'name',
        'age',
        'price',
        'is_active',
        'metadata',
        'settings',
        'tags',
        'published_at',
        'birth_date',
        'content',
        'status',
    ];

    protected array $casts = [
        'age' => 'integer',
        'price' => 'float',
        'is_active' => 'boolean',
        'metadata' => 'array',
        'settings' => AsArrayObject::class,
        'tags' => AsCollection::class,
        'published_at' => 'immutable_datetime',
        'birth_date' => 'immutable_date',
        'status' => CastStatus::class,
    ];
}
