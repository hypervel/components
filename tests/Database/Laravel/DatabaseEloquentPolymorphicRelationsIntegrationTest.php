<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentPolymorphicRelationsIntegrationTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\Relation;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentPolymorphicRelationsIntegrationTest extends TestCase
{
    /**
     * Bootstrap Eloquent.
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

    protected function createSchema(): void
    {
        $this->schema('default')->create('posts', function ($table) {
            $table->increments('id');
            $table->timestamps();
        });

        $this->schema('default')->create('images', function ($table) {
            $table->increments('id');
            $table->timestamps();
        });

        $this->schema('default')->create('tags', function ($table) {
            $table->increments('id');
            $table->timestamps();
        });

        $this->schema('default')->create('taggables', function ($table) {
            $table->integer('tag_id');
            $table->integer('taggable_id');
            $table->string('taggable_type');
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        foreach (['default'] as $connection) {
            $this->schema($connection)->drop('posts');
            $this->schema($connection)->drop('images');
            $this->schema($connection)->drop('tags');
            $this->schema($connection)->drop('taggables');
        }

        Relation::morphMap([], false);

        parent::tearDown();
    }

    public function testCreation()
    {
        $post = Post::create();
        $image = Image::create();
        $tag = Tag::create();
        $tag2 = Tag::create();

        $post->tags()->attach($tag->id);
        $post->tags()->attach($tag2->id);
        $image->tags()->attach($tag->id);

        $this->assertCount(2, $post->tags);
        $this->assertCount(1, $image->tags);
        $this->assertCount(1, $tag->posts);
        $this->assertCount(1, $tag->images);
        $this->assertCount(1, $tag2->posts);
        $this->assertCount(0, $tag2->images);
    }

    public function testEagerLoading()
    {
        $post = Post::create();
        $tag = Tag::create();
        $post->tags()->attach($tag->id);

        $post = Post::with('tags')->whereId(1)->first();
        $tag = Tag::with('posts')->whereId(1)->first();

        $this->assertTrue($post->relationLoaded('tags'));
        $this->assertTrue($tag->relationLoaded('posts'));
        $this->assertEquals($tag->id, $post->tags->first()->id);
        $this->assertEquals($post->id, $tag->posts->first()->id);
    }

    public function testChunkById()
    {
        $post = Post::create();
        $tag1 = Tag::create();
        $tag2 = Tag::create();
        $tag3 = Tag::create();
        $post->tags()->attach([$tag1->id, $tag2->id, $tag3->id]);

        $count = 0;
        $iterations = 0;
        $post->tags()->chunkById(2, function ($tags) use (&$iterations, &$count) {
            $this->assertInstanceOf(Tag::class, $tags->first());
            $count += $tags->count();
            ++$iterations;
        });

        $this->assertEquals(2, $iterations);
        $this->assertEquals(3, $count);
    }

    /**
     * Get a database connection instance.
     */
    protected function connection(string $connection = 'default'): \Hypervel\Database\Connection
    {
        return Eloquent::getConnectionResolver()->connection($connection);
    }

    /**
     * Get a schema builder instance.
     */
    protected function schema(string $connection = 'default'): \Hypervel\Database\Schema\Builder
    {
        return $this->connection($connection)->getSchemaBuilder();
    }
}

/**
 * Eloquent Models...
 */
class Post extends Eloquent
{
    protected ?string $table = 'posts';

    protected array $guarded = [];

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}

class Image extends Eloquent
{
    protected ?string $table = 'images';

    protected array $guarded = [];

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}

class Tag extends Eloquent
{
    protected ?string $table = 'tags';

    protected array $guarded = [];

    public function posts()
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }

    public function images()
    {
        return $this->morphedByMany(Image::class, 'taggable');
    }
}
