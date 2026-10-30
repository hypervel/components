<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Builder;
use Hypervel\Tests\TestCase;

class DatabaseEloquentWithAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $db = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();
    }

    protected function tearDown(): void
    {
        $this->schema()->dropIfExists((new WithAttributesModel)->getTable());

        parent::tearDown();
    }

    public function testAddsAttributes(): void
    {
        $key = 'a key';
        $value = 'the value';

        $query = WithAttributesModel::query()
            ->withAttributes([$key => $value]);

        $model = $query->make();

        $this->assertSame($value, $model->{$key});
    }

    public function testAddsWheres(): void
    {
        $key = 'a key';
        $value = 'the value';

        $query = WithAttributesModel::query()
            ->withAttributes([$key => $value]);

        $wheres = $query->toBase()->wheres;

        $this->assertContains([
            'type' => 'Basic',
            'column' => 'with_attributes_models.' . $key,
            'operator' => '=',
            'value' => $value,
            'boolean' => 'and',
        ], $wheres);
    }

    public function testAddsWithCasts(): void
    {
        $query = WithAttributesModel::query()
            ->withAttributes([
                'is_admin' => 1,
                'first_name' => 'FIRST',
                'last_name' => 'LAST',
                'type' => WithAttributesEnum::Internal,
            ]);

        $model = $query->make();

        $this->assertTrue($model->is_admin);
        $this->assertSame('First', $model->first_name);
        $this->assertSame('Last', $model->last_name);
        $this->assertSame(WithAttributesEnum::Internal, $model->type);

        $this->assertEqualsCanonicalizing([
            'is_admin' => 1,
            'first_name' => 'first',
            'last_name' => 'last',
            'type' => 'int',
        ], $model->getAttributes());
    }

    public function testAddsWithCastsViaDb(): void
    {
        $this->bootTable();

        $query = WithAttributesModel::query()
            ->withAttributes([
                'is_admin' => 1,
                'first_name' => 'FIRST',
                'last_name' => 'LAST',
                'type' => WithAttributesEnum::Internal,
            ]);

        $query->create();

        $model = WithAttributesModel::first();

        $this->assertTrue($model->is_admin);
        $this->assertSame('First', $model->first_name);
        $this->assertSame('Last', $model->last_name);
        $this->assertSame(WithAttributesEnum::Internal, $model->type);
    }

    public function testForceCreateAddsAttributesViaDb(): void
    {
        $this->bootTable();

        WithAttributesModel::query()
            ->withAttributes([
                'is_admin' => 1,
                'type' => WithAttributesEnum::Internal,
            ])
            ->forceCreate(['first_name' => 'FIRST', 'last_name' => 'LAST']);

        $model = WithAttributesModel::first();

        $this->assertTrue($model->is_admin);
        $this->assertSame(WithAttributesEnum::Internal, $model->type);
    }

    public function testForceCreateAppliesPendingAttributeMutatorsOnce(): void
    {
        $this->bootTable();
        $stream = fopen('php://memory', 'w+');

        try {
            fwrite($stream, 'first');
            rewind($stream);

            $model = WithAttributesStreamModel::query()
                ->withAttributes(['first_name' => $stream])
                ->forceCreate([
                    'last_name' => 'last',
                    'is_admin' => false,
                    'type' => WithAttributesEnum::Internal,
                ]);

            $this->assertSame('first', $model->fresh()->getRawOriginal('first_name'));
        } finally {
            fclose($stream);
        }
    }

    protected function bootTable(): void
    {
        $this->schema()->create((new WithAttributesModel)->getTable(), function ($table) {
            $table->id();
            $table->boolean('is_admin');
            $table->string('first_name');
            $table->string('last_name');
            $table->string('type');
            $table->timestamps();
        });
    }

    protected function schema(): Builder
    {
        return WithAttributesModel::getConnectionResolver()->connection()->getSchemaBuilder();
    }
}

class WithAttributesModel extends Model
{
    protected array $guarded = [];

    protected array $casts = [
        'is_admin' => 'boolean',
        'type' => WithAttributesEnum::class,
    ];

    public function setFirstNameAttribute(string $value): void
    {
        $this->attributes['first_name'] = strtolower($value);
    }

    public function getFirstNameAttribute(?string $value): string
    {
        return ucfirst($value);
    }

    protected function lastName(): Attribute
    {
        return Attribute::make(
            get: fn (string $value) => ucfirst($value),
            set: fn (string $value) => strtolower($value),
        );
    }
}

class WithAttributesStreamModel extends WithAttributesModel
{
    protected ?string $table = 'with_attributes_models';

    /**
     * Read the pending name from its stream.
     */
    public function setFirstNameAttribute(mixed $value): void
    {
        $this->attributes['first_name'] = stream_get_contents($value);
    }
}

enum WithAttributesEnum: string
{
    case Internal = 'int';
}
