<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentTouchParentWithGlobalScopeTest;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentTouchParentWithGlobalScopeTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('post_id');
            $table->string('title');
            $table->timestamps();
        });
    }

    public function testBasicCreateAndRetrieve()
    {
        $post = Post::create(['title' => Str::random(), 'updated_at' => '2016-10-10 10:10:10']);

        $this->assertSame('2016-10-10', $post->fresh()->updated_at->toDateString());

        $post->comments()->create(['title' => Str::random()]);

        $this->assertNotSame('2016-10-10', $post->fresh()->updated_at->toDateString());
    }
}

class Post extends Model
{
    protected ?string $table = 'posts';

    public bool $timestamps = true;

    protected array $guarded = [];

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class, 'post_id');
    }

    public static function boot(): void
    {
        parent::boot();

        static::addGlobalScope('age', function (Builder $builder) {
            $builder->join('comments', 'comments.post_id', '=', 'posts.id');
        });
    }
}

class Comment extends Model
{
    protected ?string $table = 'comments';

    public bool $timestamps = true;

    protected array $guarded = [];

    protected array $touches = ['post'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class, 'post_id');
    }
}
