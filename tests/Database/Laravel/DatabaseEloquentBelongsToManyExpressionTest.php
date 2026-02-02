<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentBelongsToManyExpressionTest;

use Exception;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Query\Expression;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Tests\TestCase;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentBelongsToManyExpressionTest extends TestCase
{
    protected function setUp(): void
    {
        $db = new DB();

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();
    }

    public function testAmbiguousColumnsExpression(): void
    {
        $this->seedData();

        $tags = Post::findOrFail(1)
            ->tags()
            ->wherePivotNotIn(new Expression("tag_id || '_' || type"), ['1_t1'])
            ->get();

        $this->assertCount(1, $tags);
        $this->assertEquals(2, $tags->first()->getKey());
    }

    public function testQualifiedColumnExpression(): void
    {
        $this->seedData();

        $tags = Post::findOrFail(2)
            ->tags()
            ->wherePivotNotIn(new Expression("taggables.tag_id || '_' || taggables.type"), ['2_t2'])
            ->get();

        $this->assertCount(1, $tags);
        $this->assertEquals(3, $tags->first()->getKey());
    }

    public function testGlobalScopesAreAppliedToBelongsToManyRelation(): void
    {
        $this->seedData();
        $post = Post::query()->firstOrFail();
        Tag::addGlobalScope(
            'default',
            static fn () => throw new Exception('Default global scope.')
        );

        $this->expectExceptionMessage('Default global scope.');
        $post->tags()->get();
    }

    public function testGlobalScopesCanBeRemovedFromBelongsToManyRelation(): void
    {
        $this->seedData();
        $post = Post::query()->firstOrFail();
        Tag::addGlobalScope(
            'default',
            static fn () => throw new Exception('Default global scope.')
        );

        $this->assertNotEmpty($post->tags()->withoutGlobalScopes()->get());
    }

    /**
     * Setup the database schema.
     */
    public function createSchema()
    {
        $this->schema()->create('posts', fn (Blueprint $t) => $t->id());
        $this->schema()->create('tags', fn (Blueprint $t) => $t->id());
        $this->schema()->create(
            'taggables',
            function (Blueprint $t) {
                $t->unsignedBigInteger('tag_id');
                $t->unsignedBigInteger('taggable_id');
                $t->string('type', 10);
                $t->string('taggable_type');
            }
        );
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('posts');
        $this->schema()->drop('tags');
        $this->schema()->drop('taggables');

        parent::tearDown();
    }

    /**
     * Helpers...
     */
    protected function seedData(): void
    {
        $p1 = Post::query()->create();
        $p2 = Post::query()->create();
        $t1 = Tag::query()->create();
        $t2 = Tag::query()->create();
        $t3 = Tag::query()->create();

        $p1->tags()->sync([
            $t1->getKey() => ['type' => 't1'],
            $t2->getKey() => ['type' => 't2'],
        ]);
        $p2->tags()->sync([
            $t2->getKey() => ['type' => 't2'],
            $t3->getKey() => ['type' => 't3'],
        ]);
    }

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\ConnectionInterface
     */
    protected function connection()
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get a schema builder instance.
     *
     * @return \Illuminate\Database\Schema\Builder
     */
    protected function schema()
    {
        return $this->connection()->getSchemaBuilder();
    }
}

class Post extends Eloquent
{
    protected ?string $table = 'posts';

    protected array $fillable = ['id'];

    public bool $timestamps = false;

    public function tags(): MorphToMany
    {
        return $this->morphToMany(
            Tag::class,
            'taggable',
            'taggables',
            'taggable_id',
            'tag_id',
            'id',
            'id',
        );
    }
}

class Tag extends Eloquent
{
    protected ?string $table = 'tags';

    protected array $fillable = ['id'];

    public bool $timestamps = false;
}
