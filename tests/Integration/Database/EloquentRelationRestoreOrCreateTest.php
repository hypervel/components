<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentRelationRestoreOrCreateTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
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
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('user_id')->nullable();
            $table->string('title');
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

class User extends Model
{
    public bool $timestamps = false;

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

class Role extends Model
{
    use SoftDeletes;

    public bool $timestamps = false;

    protected array $guarded = [];
}
