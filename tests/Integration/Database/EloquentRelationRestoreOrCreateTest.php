<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentRelationRestoreOrCreateTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

class EloquentRelationRestoreOrCreateTest extends DatabaseTestCase
{
    /**
     * Create the related models and their intermediate table.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('countries', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('country_id')->nullable();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('title');
            $table->softDeletes();
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('body')->unique();
            $table->softDeletes();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->softDeletes();
        });

        Schema::create('role_user', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('role_id');
        });
    }

    public function testRestoreOrCreateOnHasMany(): void
    {
        $user = User::create();
        $other = User::create();

        $post = $user->posts()->create(['title' => 'Draft']);
        $post->delete();
        $other->posts()->create(['title' => 'Draft'])->delete();

        $restored = $user->posts()->restoreOrCreate(['title' => 'Draft']);

        $this->assertTrue($restored->is($post));
        $this->assertFalse($restored->trashed());

        $created = $user->posts()->restoreOrCreate(['title' => 'Published']);

        $this->assertEquals($user->id, $created->user_id);
        $this->assertEquals($user->id, Post::where('title', 'Published')->value('user_id'));
    }

    public function testCreateOrRestoreOnHasMany(): void
    {
        $user = User::create();

        $created = $user->posts()->createOrRestore(['title' => 'Draft']);

        $this->assertEquals($user->id, $created->user_id);
        $this->assertEquals($user->id, Post::where('title', 'Draft')->value('user_id'));

        $created->delete();

        $restored = $user->posts()->createOrRestore(['id' => $created->id], ['title' => 'Draft']);

        $this->assertTrue($restored->is($created));
        $this->assertFalse($restored->trashed());
    }

    public function testRestoreOrCreateOnHasManyThrough(): void
    {
        $country = Country::create();
        $otherUser = User::create(['country_id' => Country::create()->id]);
        $user = User::create(['country_id' => $country->id]);

        $otherUser->posts()->create(['title' => 'Other'])->delete();
        $otherUser->posts()->create(['title' => 'Other'])->delete();
        $post = $user->posts()->create(['title' => 'Draft']);
        $post->delete();

        $restored = $country->posts()->restoreOrCreate(['title' => 'Draft']);

        $this->assertTrue($restored->is($post));
        $this->assertFalse($post->fresh()->trashed());
        $this->assertSame(2, Post::onlyTrashed()->where('title', 'Other')->count());
    }

    public function testCreateOrRestoreOnHasManyThrough(): void
    {
        $country = Country::create();
        $otherUser = User::create(['country_id' => Country::create()->id]);
        $user = User::create(['country_id' => $country->id]);

        $otherUser->comments()->create(['body' => 'First'])->delete();
        $otherUser->comments()->create(['body' => 'Second'])->delete();
        $comment = $user->comments()->create(['body' => 'Draft']);
        $comment->delete();

        $restored = $country->comments()->createOrRestore(['body' => 'Draft']);

        $this->assertTrue($restored->is($comment));
        $this->assertFalse($comment->fresh()->trashed());
        $this->assertSame(2, Comment::onlyTrashed()->count());
    }

    public function testRestoreOrCreateOnBelongsToMany(): void
    {
        $user = User::create();

        $other = Role::create(['name' => 'other']);
        $other->delete();

        $editor = Role::create(['name' => 'editor']);
        $user->roles()->attach($editor);
        $editor->delete();

        $restored = $user->roles()->restoreOrCreate(['name' => 'editor']);

        $this->assertTrue($restored->is($editor));
        $this->assertFalse($editor->fresh()->trashed());
        $this->assertTrue($other->fresh()->trashed());

        $created = $user->roles()->restoreOrCreate(['name' => 'admin']);

        $this->assertTrue($user->roles()->whereKey($created->getKey())->exists());
    }

    public function testRestoreOrCreateOnBelongsToManyRestoresUnattachedModel(): void
    {
        $user = User::create();

        $role = Role::create(['name' => 'admin']);
        $role->delete();

        $restored = $user->roles()->restoreOrCreate(['name' => 'admin']);

        $this->assertTrue($restored->is($role));
        $this->assertFalse($role->fresh()->trashed());
        $this->assertSame(1, Role::withTrashed()->count());
        $this->assertTrue($user->roles()->whereKey($role->getKey())->exists());
    }

    public function testCreateOrRestoreOnBelongsToMany(): void
    {
        $user = User::create();

        $created = $user->roles()->createOrRestore(['name' => 'admin']);

        $this->assertTrue($user->roles()->whereKey($created->getKey())->exists());

        $user->roles()->detach($created);
        $created->delete();

        $restored = $user->roles()->createOrRestore(['id' => $created->id], ['name' => 'admin']);

        $this->assertTrue($restored->is($created));
        $this->assertFalse($restored->trashed());
        $this->assertTrue($user->roles()->whereKey($created->getKey())->exists());

        $restored->delete();
        $user->roles()->createOrRestore(['id' => $created->id], ['name' => 'admin']);

        $this->assertSame(1, $user->roles()->count());
    }
}

class Country extends Model
{
    public bool $timestamps = false;

    /**
     * Get the country's posts.
     *
     * @return HasManyThrough<Post, User, $this>
     */
    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class);
    }

    /**
     * Get the country's comments.
     *
     * @return HasManyThrough<Comment, User, $this>
     */
    public function comments(): HasManyThrough
    {
        return $this->hasManyThrough(Comment::class, User::class);
    }
}

class User extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    /**
     * Get the user's posts.
     *
     * @return HasMany<Post, $this>
     */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /**
     * Get the user's comments.
     *
     * @return HasMany<Comment, $this>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * Get the user's roles.
     *
     * @return BelongsToMany<Role, $this>
     */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }
}

class Post extends Model
{
    use SoftDeletes;

    public bool $timestamps = false;

    protected array $guarded = [];
}

class Comment extends Model
{
    use SoftDeletes;

    public bool $timestamps = false;

    protected array $guarded = [];
}

class Role extends Model
{
    use SoftDeletes;

    public bool $timestamps = false;

    protected array $guarded = [];
}
