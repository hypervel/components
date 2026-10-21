<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Eloquent\Casts\AsArrayObject;
use Hypervel\Database\Eloquent\Casts\AsCollection;
use Hypervel\Database\Eloquent\Casts\AsStringable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Fluent;
use Hypervel\Support\Stringable;

class DatabaseCustomCastsTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('test_eloquent_model_with_custom_casts', function (Blueprint $table) {
            $table->increments('id');
            $table->text('array_object');
            $table->json('array_object_json');
            $table->text('collection');
            $table->string('stringable');
            $table->string('password');
            $table->timestamps();
        });

        Schema::create('test_eloquent_model_with_custom_casts_nullables', function (Blueprint $table) {
            $table->increments('id');
            $table->text('array_object')->nullable();
            $table->json('array_object_json')->nullable();
            $table->text('collection')->nullable();
            $table->string('stringable')->nullable();
            $table->timestamps();
        });
    }

    public function testCustomCasting(): void
    {
        $model = new TestEloquentModelWithCustomCasts;

        $model->array_object = ['name' => 'Taylor'];
        $model->array_object_json = ['name' => 'Taylor'];
        $model->collection = collect(['name' => 'Taylor']);
        $model->stringable = new Stringable('Taylor');
        $model->password = Hash::make('secret');

        $model->save();

        $model = $model->fresh();

        $this->assertEquals(['name' => 'Taylor'], $model->array_object->toArray());
        $this->assertEquals(['name' => 'Taylor'], $model->array_object_json->toArray());
        $this->assertEquals(['name' => 'Taylor'], $model->collection->toArray());
        $this->assertSame('Taylor', (string) $model->stringable);
        $this->assertTrue(Hash::check('secret', $model->password));

        $model->array_object['age'] = 34;
        $model->array_object['meta']['title'] = 'Developer';

        $model->array_object_json['age'] = 34;
        $model->array_object_json['meta']['title'] = 'Developer';

        $model->save();

        $model = $model->fresh();

        $this->assertEquals(
            [
                'name' => 'Taylor',
                'age' => 34,
                'meta' => ['title' => 'Developer'],
            ],
            $model->array_object->toArray()
        );

        $this->assertEquals(
            [
                'name' => 'Taylor',
                'age' => 34,
                'meta' => ['title' => 'Developer'],
            ],
            $model->array_object_json->toArray()
        );
    }

    public function testCustomCastingUsingCreate(): void
    {
        $model = TestEloquentModelWithCustomCasts::create([
            'array_object' => ['name' => 'Taylor'],
            'array_object_json' => ['name' => 'Taylor'],
            'collection' => collect(['name' => 'Taylor']),
            'stringable' => new Stringable('Taylor'),
            'password' => Hash::make('secret'),
        ]);

        $model->save();

        $model = $model->fresh();

        $this->assertEquals(['name' => 'Taylor'], $model->array_object->toArray());
        $this->assertEquals(['name' => 'Taylor'], $model->array_object_json->toArray());
        $this->assertEquals(['name' => 'Taylor'], $model->collection->toArray());
        $this->assertSame('Taylor', (string) $model->stringable);
        $this->assertTrue(Hash::check('secret', $model->password));
    }

    public function testCustomCastingNullableValues(): void
    {
        $model = new TestEloquentModelWithCustomCastsNullable;

        $model->array_object = null;
        $model->array_object_json = null;
        $model->collection = collect();
        $model->stringable = null;

        $model->save();

        $model = $model->fresh();

        $this->assertEmpty($model->array_object);
        $this->assertEmpty($model->array_object_json);
        $this->assertEmpty($model->collection);
        $this->assertSame('', (string) $model->stringable);

        $model->array_object = ['name' => 'John'];
        $model->array_object['name'] = 'Taylor';
        $model->array_object['meta']['title'] = 'Developer';

        $model->array_object_json = ['name' => 'John'];
        $model->array_object_json['name'] = 'Taylor';
        $model->array_object_json['meta']['title'] = 'Developer';

        $model->save();

        $model = $model->fresh();

        $this->assertEquals(
            [
                'name' => 'Taylor',
                'meta' => ['title' => 'Developer'],
            ],
            $model->array_object->toArray()
        );

        $this->assertEquals(
            [
                'name' => 'Taylor',
                'meta' => ['title' => 'Developer'],
            ],
            $model->array_object_json->toArray()
        );
    }

    public function testDefaultCastsStillPersistNullAsJsonNullLiteral(): void
    {
        $model = new TestEloquentModelWithCustomCastsNullable;
        $model->array_object_json = null;
        $model->save();

        $this->assertSame('null', $model->getRawOriginal('array_object_json'));

        $this->assertFalse(
            TestEloquentModelWithCustomCastsNullable::whereNull('array_object_json')->exists()
        );
    }

    public function testNullableClassCastsPersistRealNull(): void
    {
        $model = new TestEloquentModelWithNullableCustomCasts;

        $model->array_object = null;
        $model->array_object_json = null;
        $model->collection = null;

        $model->save();

        $this->assertNull($model->getRawOriginal('array_object'));
        $this->assertNull($model->getRawOriginal('array_object_json'));
        $this->assertNull($model->getRawOriginal('collection'));

        $this->assertTrue(
            TestEloquentModelWithNullableCustomCasts::whereNull('array_object_json')->exists()
        );

        $model = $model->fresh();

        $this->assertNull($model->array_object);
        $this->assertNull($model->array_object_json);
        $this->assertNull($model->collection);

        $model->array_object_json = ['name' => 'Taylor'];
        $model->save();

        $this->assertEquals(['name' => 'Taylor'], $model->fresh()->array_object_json->toArray());
    }

    public function testAsCollectionNullableWithCustomCollectionClass(): void
    {
        $model = new TestEloquentModelWithCustomCasts;
        $model->mergeCasts([
            'collection' => AsCollection::nullable(CustomCollection::class),
        ]);

        $model->collection = null;
        $this->assertNull($model->getAttributes()['collection']);

        $model->setRawAttributes(['collection' => json_encode(['foo' => 'bar'])]);

        /** @var CustomCollection $collection */
        $collection = $model->collection;

        $this->assertInstanceOf(CustomCollection::class, $collection);
        $this->assertSame('bar', $collection->first());
    }

    public function testAsCollectionWithMapInto(): void
    {
        $model = new TestEloquentModelWithCustomCasts;
        $model->mergeCasts([
            'collection' => AsCollection::of(Fluent::class),
        ]);

        $model->setRawAttributes([
            'collection' => json_encode([['foo' => 'bar']]),
        ]);

        $this->assertInstanceOf(Fluent::class, $model->collection->first());
        $this->assertSame('bar', $model->collection->first()->foo);
    }

    public function testAsCustomCollectionWithMapInto(): void
    {
        $model = new TestEloquentModelWithCustomCasts;
        $model->mergeCasts([
            'collection' => AsCollection::using(CustomCollection::class, Fluent::class),
        ]);

        $model->setRawAttributes([
            'collection' => json_encode([['foo' => 'bar']]),
        ]);

        $this->assertInstanceOf(CustomCollection::class, $model->collection);
        $this->assertInstanceOf(Fluent::class, $model->collection->first());
        $this->assertSame('bar', $model->collection->first()->foo);
    }

    public function testAsCollectionWithMapCallback(): void
    {
        $model = new TestEloquentModelWithCustomCasts;
        $model->mergeCasts([
            'collection' => AsCollection::of([FluentWithCallback::class, 'make']),
        ]);

        $model->setRawAttributes([
            'collection' => json_encode([['foo' => 'bar']]),
        ]);

        $this->assertInstanceOf(FluentWithCallback::class, $model->collection->first());
        $this->assertSame('bar', $model->collection->first()->foo);
    }

    public function testAsCustomCollectionWithMapCallback(): void
    {
        $model = new TestEloquentModelWithCustomCasts;
        $model->mergeCasts([
            'collection' => AsCollection::using(CustomCollection::class, [FluentWithCallback::class, 'make']),
        ]);

        $model->setRawAttributes([
            'collection' => json_encode([['foo' => 'bar']]),
        ]);

        $this->assertInstanceOf(CustomCollection::class, $model->collection);
        $this->assertInstanceOf(FluentWithCallback::class, $model->collection->first());
        $this->assertSame('bar', $model->collection->first()->foo);
    }
}

class TestEloquentModelWithCustomCasts extends Model
{
    protected array $guarded = [];

    protected array $casts = [
        'array_object' => AsArrayObject::class,
        'array_object_json' => AsArrayObject::class,
        'collection' => AsCollection::class,
        'stringable' => AsStringable::class,
        'password' => 'hashed',
    ];
}

class TestEloquentModelWithCustomCastsNullable extends Model
{
    protected array $guarded = [];

    protected array $casts = [
        'array_object' => AsArrayObject::class,
        'array_object_json' => AsArrayObject::class,
        'collection' => AsCollection::class,
        'stringable' => AsStringable::class,
    ];
}

class TestEloquentModelWithNullableCustomCasts extends Model
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'test_eloquent_model_with_custom_casts_nullables';

    /**
     * The attributes that aren't mass assignable.
     *
     * @var string[]
     */
    protected array $guarded = [];

    /**
     * Get the attributes that should be cast.
     */
    protected function casts(): array
    {
        return [
            'array_object' => AsArrayObject::nullable(),
            'array_object_json' => AsArrayObject::nullable(),
            'collection' => AsCollection::nullable(),
            'stringable' => AsStringable::class,
        ];
    }
}

class FluentWithCallback extends Fluent
{
    public static function make(array|object $attributes = []): static
    {
        return new static($attributes);
    }
}

class CustomCollection extends Collection
{
}
