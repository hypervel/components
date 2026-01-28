<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentInverseRelationHasOneTest extends TestCase
{
    /**
     * Setup the database schema.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $db = new DB();

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);
        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();
    }

    protected function createSchema()
    {
        $this->schema()->create('test_parent', function ($table) {
            $table->increments('id');
            $table->timestamps();
        });

        $this->schema()->create('test_child', function ($table) {
            $table->increments('id');
            $table->foreignId('parent_id')->unique();
            $table->timestamps();
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('test_parent');
        $this->schema()->drop('test_child');

        parent::tearDown();
    }

    public function testHasOneInverseRelationIsProperlySetToParentWhenLazyLoaded()
    {
        HasOneRelationInverseChildModel::factory(5)->create();
        $models = HasOneInverseParentModel::all();

        foreach ($models as $parent) {
            $this->assertFalse($parent->relationLoaded('child'));
            $child = $parent->child;
            $this->assertTrue($child->relationLoaded('parent'));
            $this->assertSame($parent, $child->parent);
        }
    }

    public function testHasOneInverseRelationIsProperlySetToParentWhenEagerLoaded()
    {
        HasOneRelationInverseChildModel::factory(5)->create();

        $models = HasOneInverseParentModel::with('child')->get();

        foreach ($models as $parent) {
            $child = $parent->child;

            $this->assertTrue($child->relationLoaded('parent'));
            $this->assertSame($parent, $child->parent);
        }
    }

    public function testHasOneInverseRelationIsProperlySetToParentWhenMaking()
    {
        $parent = HasOneInverseParentModel::create();

        $child = $parent->child()->make();

        $this->assertTrue($child->relationLoaded('parent'));
        $this->assertSame($parent, $child->parent);
    }

    public function testHasOneInverseRelationIsProperlySetToParentWhenCreating()
    {
        $parent = HasOneInverseParentModel::create();

        $child = $parent->child()->create();

        $this->assertTrue($child->relationLoaded('parent'));
        $this->assertSame($parent, $child->parent);
    }

    public function testHasOneInverseRelationIsProperlySetToParentWhenCreatingQuietly()
    {
        $parent = HasOneInverseParentModel::create();

        $child = $parent->child()->createQuietly();

        $this->assertTrue($child->relationLoaded('parent'));
        $this->assertSame($parent, $child->parent);
    }

    public function testHasOneInverseRelationIsProperlySetToParentWhenForceCreating()
    {
        $parent = HasOneInverseParentModel::create();

        $child = $parent->child()->forceCreate();

        $this->assertTrue($child->relationLoaded('parent'));
        $this->assertSame($parent, $child->parent);
    }

    public function testHasOneInverseRelationIsProperlySetToParentWhenSaving()
    {
        $parent = HasOneInverseParentModel::create();
        $child = HasOneRelationInverseChildModel::make();

        $this->assertFalse($child->relationLoaded('parent'));
        $parent->child()->save($child);

        $this->assertTrue($child->relationLoaded('parent'));
        $this->assertSame($parent, $child->parent);
    }

    public function testHasOneInverseRelationIsProperlySetToParentWhenSavingQuietly()
    {
        $parent = HasOneInverseParentModel::create();
        $child = HasOneRelationInverseChildModel::make();

        $this->assertFalse($child->relationLoaded('parent'));
        $parent->child()->saveQuietly($child);

        $this->assertTrue($child->relationLoaded('parent'));
        $this->assertSame($parent, $child->parent);
    }

    public function testHasOneInverseRelationIsProperlySetToParentWhenUpdating()
    {
        $parent = HasOneInverseParentModel::create();
        $child = HasOneRelationInverseChildModel::factory()->create();

        $this->assertTrue($parent->isNot($child->parent));

        $parent->child()->save($child);

        $this->assertTrue($parent->is($child->parent));
        $this->assertSame($parent, $child->parent);
    }

    /**
     * Helpers...
     * @param mixed $connection
     */

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\Connection
     */
    protected function connection($connection = 'default')
    {
        return Eloquent::getConnectionResolver()->connection($connection);
    }

    /**
     * Get a schema builder instance.
     *
     * @param mixed $connection
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema($connection = 'default')
    {
        return $this->connection($connection)->getSchemaBuilder();
    }
}

class HasOneInverseParentModel extends Model
{
    use HasFactory;

    protected ?string $table = 'test_parent';

    protected array $fillable = ['id'];

    protected static function newFactory(): HasOneInverseParentModelFactory
    {
        return new HasOneInverseParentModelFactory();
    }

    public function child(): HasOne
    {
        return $this->hasOne(HasOneRelationInverseChildModel::class, 'parent_id')->inverse('parent');
    }
}

class HasOneInverseParentModelFactory extends Factory
{
    protected ?string $model = HasOneInverseParentModel::class;

    public function definition(): array
    {
        return [];
    }
}

class HasOneRelationInverseChildModel extends Model
{
    use HasFactory;

    protected ?string $table = 'test_child';

    protected array $fillable = ['id', 'parent_id'];

    protected static function newFactory(): HasOneRelationInverseChildModelFactory
    {
        return new HasOneRelationInverseChildModelFactory();
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(HasOneInverseParentModel::class, 'parent_id');
    }
}

class HasOneRelationInverseChildModelFactory extends Factory
{
    protected ?string $model = HasOneRelationInverseChildModel::class;

    public function definition(): array
    {
        return [
            'parent_id' => HasOneInverseParentModel::factory(),
        ];
    }
}
