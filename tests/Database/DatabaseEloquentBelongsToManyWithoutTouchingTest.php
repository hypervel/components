<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\SQLiteConnection;
use Hypervel\Tests\Database\Concerns\InteractsWithInMemoryConnection;
use Hypervel\Tests\TestCase;

class DatabaseEloquentBelongsToManyWithoutTouchingTest extends TestCase
{
    use InteractsWithInMemoryConnection;

    /**
     * Create an in-memory connection with a user attached to an article.
     */
    protected function newConnection(): SQLiteConnection
    {
        $connection = $this->useInMemoryConnection();
        $pdo = $connection->getPdo();
        $pdo->exec('create table "users" ("id" integer primary key)');
        $pdo->exec('create table "articles" ("id" integer primary key, "title" text, "updated_at" text)');
        $pdo->exec('create table "article_user" ("user_id" integer, "article_id" integer)');
        $pdo->exec('insert into "users" values (1)');
        $pdo->exec("insert into \"articles\" values (1, 'title', null)");
        $pdo->exec('insert into "article_user" values (1, 1)');

        return $connection;
    }

    public function testItWillNotTouchRelatedModelsWhenUpdatingChild(): void
    {
        $connection = $this->newConnection();
        $user = new BelongsToManyWithoutTouchingUser(['id' => 1]);
        $builder = (new Builder($connection->query()))->setModel(new BelongsToManyWithoutTouchingArticle);
        $relation = new BelongsToMany($builder, $user, 'article_user', 'user_id', 'article_id', 'id', 'id');

        $this->assertFalse(BelongsToManyWithoutTouchingArticle::isIgnoringTouch());

        Model::withoutTouching(function () use ($relation): void {
            $this->assertTrue(BelongsToManyWithoutTouchingArticle::isIgnoringTouch());

            $relation->touch();
        });

        $this->assertNull($connection->scalar('select "updated_at" from "articles"'));

        $relation->touch();

        $this->assertNotNull($connection->scalar('select "updated_at" from "articles"'));
    }
}

class BelongsToManyWithoutTouchingUser extends Model
{
    protected ?string $table = 'users';

    protected array $fillable = ['id', 'email'];

    /**
     * Get the user's articles.
     */
    public function articles(): BelongsToMany
    {
        return $this->belongsToMany(BelongsToManyWithoutTouchingArticle::class, 'article_user', 'user_id', 'article_id');
    }
}

class BelongsToManyWithoutTouchingArticle extends Model
{
    protected ?string $table = 'articles';

    protected ?string $dateFormat = 'Y-m-d H:i:s';

    protected array $fillable = ['id', 'title'];

    protected array $touches = ['user'];

    /**
     * Get the article's users.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(BelongsToManyWithoutTouchingUser::class, 'article_user', 'article_id', 'user_id');
    }
}
