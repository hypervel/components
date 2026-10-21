<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\MorphOne;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Schema\Builder;
use Hypervel\Tests\TestCase;

class DatabaseEloquentInverseRelationMorphOneTest extends TestCase
{
    /**
     * Setup the database schema.
     */
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

        $this->createSchema();
    }

    protected function createSchema()
    {
        $this->schema()->create('test_posts', function ($table) {
            $table->increments('id');
            $table->timestamps();
        });

        $this->schema()->create('test_images', function ($table) {
            $table->increments('id');
            $table->morphs('imageable');
            $table->timestamps();
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('test_posts');
        $this->schema()->drop('test_images');

        parent::tearDown();
    }

    public function testMorphOneInverseRelationIsProperlySetToParentWhenLazyLoaded(): void
    {
        MorphOneInverseImageModel::factory(6)->create();
        $posts = MorphOneInversePostModel::all();

        foreach ($posts as $post) {
            $this->assertFalse($post->relationLoaded('image'));
            $image = $post->image;
            $this->assertTrue($image->relationLoaded('imageable'));
            $this->assertSame($post, $image->imageable);
        }
    }

    public function testMorphOneInverseRelationIsProperlySetToParentWhenEagerLoaded(): void
    {
        MorphOneInverseImageModel::factory(6)->create();
        $posts = MorphOneInversePostModel::with('image')->get();

        foreach ($posts as $post) {
            $image = $post->getRelation('image');

            $this->assertTrue($image->relationLoaded('imageable'));
            $this->assertSame($post, $image->imageable);
        }
    }

    public function testMorphOneGuessedInverseRelationIsProperlySetToParentWhenLazyLoaded(): void
    {
        MorphOneInverseImageModel::factory(6)->create();
        $posts = MorphOneInversePostModel::all();

        foreach ($posts as $post) {
            $this->assertFalse($post->relationLoaded('guessedImage'));
            $image = $post->guessedImage;
            $this->assertTrue($image->relationLoaded('imageable'));
            $this->assertSame($post, $image->imageable);
        }
    }

    public function testMorphOneGuessedInverseRelationIsProperlySetToParentWhenEagerLoaded(): void
    {
        MorphOneInverseImageModel::factory(6)->create();
        $posts = MorphOneInversePostModel::with('guessedImage')->get();

        foreach ($posts as $post) {
            $image = $post->getRelation('guessedImage');

            $this->assertTrue($image->relationLoaded('imageable'));
            $this->assertSame($post, $image->imageable);
        }
    }

    public function testMorphOneInverseRelationIsProperlySetToParentWhenMaking(): void
    {
        $post = MorphOneInversePostModel::create();

        $image = $post->image()->make();

        $this->assertTrue($image->relationLoaded('imageable'));
        $this->assertSame($post, $image->imageable);
    }

    public function testMorphOneInverseRelationIsProperlySetToParentWhenCreating(): void
    {
        $post = MorphOneInversePostModel::create();

        $image = $post->image()->create();

        $this->assertTrue($image->relationLoaded('imageable'));
        $this->assertSame($post, $image->imageable);
    }

    public function testMorphOneInverseRelationIsProperlySetToParentWhenCreatingQuietly(): void
    {
        $post = MorphOneInversePostModel::create();

        $image = $post->image()->createQuietly();

        $this->assertTrue($image->relationLoaded('imageable'));
        $this->assertSame($post, $image->imageable);
    }

    public function testMorphOneInverseRelationIsProperlySetToParentWhenForceCreating(): void
    {
        $post = MorphOneInversePostModel::create();

        $image = $post->image()->forceCreate();

        $this->assertTrue($image->relationLoaded('imageable'));
        $this->assertSame($post, $image->imageable);
    }

    public function testMorphOneInverseRelationIsProperlySetToParentWhenSaving(): void
    {
        $post = MorphOneInversePostModel::create();
        $image = MorphOneInverseImageModel::make();

        $this->assertFalse($image->relationLoaded('imageable'));
        $post->image()->save($image);

        $this->assertTrue($image->relationLoaded('imageable'));
        $this->assertSame($post, $image->imageable);
    }

    public function testMorphOneInverseRelationIsProperlySetToParentWhenSavingQuietly(): void
    {
        $post = MorphOneInversePostModel::create();
        $image = MorphOneInverseImageModel::make();

        $this->assertFalse($image->relationLoaded('imageable'));
        $post->image()->saveQuietly($image);

        $this->assertTrue($image->relationLoaded('imageable'));
        $this->assertSame($post, $image->imageable);
    }

    public function testMorphOneInverseRelationIsProperlySetToParentWhenUpdating(): void
    {
        $post = MorphOneInversePostModel::create();
        $image = MorphOneInverseImageModel::factory()->create();

        $this->assertTrue($post->isNot($image->imageable));

        $post->image()->save($image);

        $this->assertTrue($post->is($image->imageable));
        $this->assertSame($post, $image->imageable);
    }

    /**
     * Helpers...
     */

    /**
     * Get a database connection instance.
     */
    protected function connection(string $connection = 'default'): ConnectionInterface
    {
        return Eloquent::getConnectionResolver()->connection($connection);
    }

    /**
     * Get a schema builder instance.
     */
    protected function schema(string $connection = 'default'): Builder
    {
        return $this->connection($connection)->getSchemaBuilder();
    }
}

class MorphOneInversePostModel extends Model
{
    use HasFactory;

    protected ?string $table = 'test_posts';

    protected array $fillable = ['id'];

    protected static function newFactory(): MorphOneInversePostModelFactory
    {
        return new MorphOneInversePostModelFactory;
    }

    public function image(): MorphOne
    {
        return $this->morphOne(MorphOneInverseImageModel::class, 'imageable')->inverse('imageable');
    }

    public function guessedImage(): MorphOne
    {
        return $this->morphOne(MorphOneInverseImageModel::class, 'imageable')->inverse();
    }
}

class MorphOneInversePostModelFactory extends Factory
{
    protected ?string $model = MorphOneInversePostModel::class;

    public function definition(): array
    {
        return [];
    }
}

class MorphOneInverseImageModel extends Model
{
    use HasFactory;

    protected ?string $table = 'test_images';

    protected array $fillable = ['id', 'imageable_type', 'imageable_id'];

    protected static function newFactory(): MorphOneInverseImageModelFactory
    {
        return new MorphOneInverseImageModelFactory;
    }

    public function imageable(): MorphTo
    {
        return $this->morphTo('imageable');
    }
}

class MorphOneInverseImageModelFactory extends Factory
{
    protected ?string $model = MorphOneInverseImageModel::class;

    public function definition(): array
    {
        return [
            'imageable_type' => MorphOneInversePostModel::class,
            'imageable_id' => MorphOneInversePostModel::factory(),
        ];
    }
}
