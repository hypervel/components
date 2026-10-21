<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentBelongsToTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

class EloquentBelongsToTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->nullable();
            $table->unsignedInteger('parent_id')->nullable();
            $table->string('parent_slug')->nullable();
        });

        $user = User::create(['slug' => Str::random()]);
        User::create(['parent_id' => $user->id, 'parent_slug' => $user->slug]);
    }

    public function testHasSelf(): void
    {
        $users = User::has('parent')->get();

        $this->assertCount(1, $users);
    }

    public function testHasSelfCustomOwnerKey(): void
    {
        $users = User::has('parentBySlug')->get();

        $this->assertCount(1, $users);
    }

    public function testAssociateWithModel(): void
    {
        $parent = User::doesntHave('parent')->first();
        $child = User::has('parent')->first();

        $parent->parent()->associate($child);

        $this->assertEquals($child->id, $parent->parent_id);
        $this->assertEquals($child->id, $parent->parent->id);
    }

    public function testAssociateWithId(): void
    {
        $parent = User::doesntHave('parent')->first();
        $child = User::has('parent')->first();

        $parent->parent()->associate($child->id);

        $this->assertEquals($child->id, $parent->parent_id);
        $this->assertEquals($child->id, $parent->parent->id);
    }

    public function testAssociateWithIdUnsetsLoadedRelation(): void
    {
        $child = User::has('parent')->with('parent')->first();

        // Overwrite the (loaded) parent relation
        $child->parent()->associate($child->id);

        $this->assertEquals($child->id, $child->parent_id);
        $this->assertFalse($child->relationLoaded('parent'));
    }

    public function testParentIsNotNull(): void
    {
        $child = User::has('parent')->first();
        $parent = null;

        $this->assertFalse($child->parent()->is($parent));
        $this->assertTrue($child->parent()->isNot($parent));
    }

    public function testParentIsModel(): void
    {
        $child = User::has('parent')->first();
        $parent = User::doesntHave('parent')->first();

        $this->assertTrue($child->parent()->is($parent));
        $this->assertFalse($child->parent()->isNot($parent));
    }

    public function testParentIsNotAnotherModel(): void
    {
        $child = User::has('parent')->first();
        $parent = new User;
        $parent->id = 3;

        $this->assertFalse($child->parent()->is($parent));
        $this->assertTrue($child->parent()->isNot($parent));
    }

    public function testNullParentIsNotModel(): void
    {
        $child = User::has('parent')->first();
        $child->parent()->dissociate();
        $parent = User::doesntHave('parent')->first();

        $this->assertFalse($child->parent()->is($parent));
        $this->assertTrue($child->parent()->isNot($parent));
    }

    public function testParentIsNotModelWithAnotherTable(): void
    {
        $child = User::has('parent')->first();
        $parent = User::doesntHave('parent')->first();
        $parent->setTable('foo');

        $this->assertFalse($child->parent()->is($parent));
        $this->assertTrue($child->parent()->isNot($parent));
    }

    public function testParentIsNotModelWithAnotherConnection(): void
    {
        $child = User::has('parent')->first();
        $parent = User::doesntHave('parent')->first();
        $parent->setConnection('foo');

        $this->assertFalse($child->parent()->is($parent));
        $this->assertTrue($child->parent()->isNot($parent));
    }
}

class User extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];

    public function parent()
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function parentBySlug()
    {
        return $this->belongsTo(self::class, 'parent_slug', 'slug');
    }
}
