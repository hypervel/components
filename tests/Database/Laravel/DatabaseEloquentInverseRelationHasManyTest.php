<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasOne;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentInverseRelationHasManyTest extends TestCase
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
        $this->schema()->create('test_users', function ($table) {
            $table->increments('id');
            $table->timestamps();
        });

        $this->schema()->create('test_posts', function ($table) {
            $table->increments('id');
            $table->foreignId('user_id');
            $table->timestamps();
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('test_users');
        $this->schema()->drop('test_posts');

        parent::tearDown();
    }

    public function testHasManyInverseRelationIsProperlySetToParentWhenLazyLoaded()
    {
        HasManyInverseUserModel::factory()->count(3)->withPosts()->create();
        $users = HasManyInverseUserModel::all();

        foreach ($users as $user) {
            $this->assertFalse($user->relationLoaded('posts'));
            foreach ($user->posts as $post) {
                $this->assertTrue($post->relationLoaded('user'));
                $this->assertSame($user, $post->user);
            }
        }
    }

    public function testHasManyInverseRelationIsProperlySetToParentWhenEagerLoaded()
    {
        HasManyInverseUserModel::factory()->count(3)->withPosts()->create();
        $users = HasManyInverseUserModel::with('posts')->get();

        foreach ($users as $user) {
            $posts = $user->getRelation('posts');

            foreach ($posts as $post) {
                $this->assertTrue($post->relationLoaded('user'));
                $this->assertSame($user, $post->user);
            }
        }
    }

    public function testHasLatestOfManyInverseRelationIsProperlySetToParentWhenLazyLoaded()
    {
        HasManyInverseUserModel::factory()->count(3)->withPosts()->create();
        $users = HasManyInverseUserModel::all();

        foreach ($users as $user) {
            $this->assertFalse($user->relationLoaded('lastPost'));
            $post = $user->lastPost;

            $this->assertTrue($post->relationLoaded('user'));
            $this->assertSame($user, $post->user);
        }
    }

    public function testHasLatestOfManyInverseRelationIsProperlySetToParentWhenEagerLoaded()
    {
        HasManyInverseUserModel::factory()->count(3)->withPosts()->create();
        $users = HasManyInverseUserModel::with('lastPost')->get();

        foreach ($users as $user) {
            $post = $user->getRelation('lastPost');

            $this->assertTrue($post->relationLoaded('user'));
            $this->assertSame($user, $post->user);
        }
    }

    public function testOneOfManyInverseRelationIsProperlySetToParentWhenLazyLoaded()
    {
        HasManyInverseUserModel::factory()->count(3)->withPosts()->create();
        $users = HasManyInverseUserModel::all();

        foreach ($users as $user) {
            $this->assertFalse($user->relationLoaded('firstPost'));
            $post = $user->firstPost;

            $this->assertTrue($post->relationLoaded('user'));
            $this->assertSame($user, $post->user);
        }
    }

    public function testOneOfManyInverseRelationIsProperlySetToParentWhenEagerLoaded()
    {
        HasManyInverseUserModel::factory()->count(3)->withPosts()->create();
        $users = HasManyInverseUserModel::with('firstPost')->get();

        foreach ($users as $user) {
            $post = $user->getRelation('firstPost');

            $this->assertTrue($post->relationLoaded('user'));
            $this->assertSame($user, $post->user);
        }
    }

    public function testHasManyInverseRelationIsProperlySetToParentWhenMakingMany()
    {
        $user = HasManyInverseUserModel::create();

        $posts = $user->posts()->makeMany(array_fill(0, 3, []));

        foreach ($posts as $post) {
            $this->assertTrue($post->relationLoaded('user'));
            $this->assertSame($user, $post->user);
        }
    }

    public function testHasManyInverseRelationIsProperlySetToParentWhenCreatingMany()
    {
        $user = HasManyInverseUserModel::create();

        $posts = $user->posts()->createMany(array_fill(0, 3, []));

        foreach ($posts as $post) {
            $this->assertTrue($post->relationLoaded('user'));
            $this->assertSame($user, $post->user);
        }
    }

    public function testHasManyInverseRelationIsProperlySetToParentWhenCreatingManyQuietly()
    {
        $user = HasManyInverseUserModel::create();

        $posts = $user->posts()->createManyQuietly(array_fill(0, 3, []));

        foreach ($posts as $post) {
            $this->assertTrue($post->relationLoaded('user'));
            $this->assertSame($user, $post->user);
        }
    }

    public function testHasManyInverseRelationIsProperlySetToParentWhenSavingMany()
    {
        $user = HasManyInverseUserModel::create();

        $posts = array_fill(0, 3, new HasManyInversePostModel());

        $user->posts()->saveMany($posts);

        foreach ($posts as $post) {
            $this->assertTrue($post->relationLoaded('user'));
            $this->assertSame($user, $post->user);
        }
    }

    public function testHasManyInverseRelationIsProperlySetToParentWhenUpdatingMany()
    {
        $user = HasManyInverseUserModel::create();

        $posts = HasManyInversePostModel::factory()->count(3)->create();

        foreach ($posts as $post) {
            $this->assertTrue($user->isNot($post->user));
        }

        $user->posts()->saveMany($posts);

        foreach ($posts as $post) {
            $this->assertSame($user, $post->user);
        }
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

class HasManyInverseUserModel extends Model
{
    use HasFactory;

    protected ?string $table = 'test_users';

    protected array $fillable = ['id'];

    protected static function newFactory(): HasManyInverseUserModelFactory
    {
        return new HasManyInverseUserModelFactory();
    }

    public function posts(): HasMany
    {
        return $this->hasMany(HasManyInversePostModel::class, 'user_id')->inverse('user');
    }

    public function lastPost(): HasOne
    {
        return $this->hasOne(HasManyInversePostModel::class, 'user_id')->latestOfMany()->inverse('user');
    }

    public function firstPost(): HasOne
    {
        return $this->posts()->one();
    }
}

class HasManyInverseUserModelFactory extends Factory
{
    protected ?string $model = HasManyInverseUserModel::class;

    public function definition(): array
    {
        return [];
    }

    public function withPosts(int $count = 3): static
    {
        return $this->afterCreating(function (HasManyInverseUserModel $model) use ($count) {
            HasManyInversePostModel::factory()->recycle($model)->count($count)->create();
        });
    }
}

class HasManyInversePostModel extends Model
{
    use HasFactory;

    protected ?string $table = 'test_posts';

    protected array $fillable = ['id', 'user_id'];

    protected static function newFactory(): HasManyInversePostModelFactory
    {
        return new HasManyInversePostModelFactory();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(HasManyInverseUserModel::class, 'user_id');
    }
}

class HasManyInversePostModelFactory extends Factory
{
    protected ?string $model = HasManyInversePostModel::class;

    public function definition(): array
    {
        return [
            'user_id' => HasManyInverseUserModel::factory(),
        ];
    }
}
