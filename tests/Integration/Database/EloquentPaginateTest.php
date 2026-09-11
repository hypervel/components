<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentPaginateTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;
use Hypervel\Tests\Integration\Database\Fixtures\Models\Guarded\Post;

class EloquentPaginateTest extends DatabaseTestCase
{
    /**
     * Set up the database after refreshing it.
     */
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
            $table->unsignedInteger('user_id')->nullable();
            $table->timestamps();
        });

        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->timestamps();
        });
    }

    public function testPaginationOnTopOfColumns(): void
    {
        for ($i = 1; $i <= 50; ++$i) {
            Post::create([
                'title' => 'Title ' . $i,
            ]);
        }

        $this->assertCount(15, Post::paginate(15, ['id', 'title']));
    }

    public function testPaginationWithDistinct(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            Post::create(['title' => 'Hello world']);
            Post::create(['title' => 'Goodbye world']);
        }

        $query = Post::query()->distinct();

        $this->assertEquals(6, $query->get()->count());
        $this->assertEquals(6, $query->count());
        $this->assertEquals(6, $query->paginate()->total());
    }

    public function testPaginationWithDistinctAndSelect(): void
    {
        // This is the 'broken' behavior, but this test is added to show backwards compatibility.
        for ($i = 1; $i <= 3; ++$i) {
            Post::create(['title' => 'Hello world']);
            Post::create(['title' => 'Goodbye world']);
        }

        $query = Post::query()->distinct()->select('title');

        $this->assertEquals(2, $query->get()->count());
        $this->assertEquals(6, $query->count());
        $this->assertEquals(6, $query->paginate()->total());
    }

    public function testPaginationWithDistinctColumnsAndSelect(): void
    {
        for ($i = 1; $i <= 3; ++$i) {
            Post::create(['title' => 'Hello world']);
            Post::create(['title' => 'Goodbye world']);
        }

        $query = Post::query()->distinct('title')->select('title');

        $this->assertEquals(2, $query->get()->count());
        $this->assertEquals(2, $query->count());
        $this->assertEquals(2, $query->paginate()->total());
    }

    public function testPaginationWithDistinctColumnsAndSelectAndJoin(): void
    {
        for ($i = 1; $i <= 5; ++$i) {
            $user = User::create();
            for ($j = 1; $j <= 10; ++$j) {
                Post::create([
                    'title' => 'Title ' . $i,
                    'user_id' => $user->id,
                ]);
            }
        }

        $query = User::query()->join('posts', 'posts.user_id', '=', 'users.id')
            ->distinct('users.id')->select('users.*');

        $this->assertEquals(5, $query->get()->count());
        $this->assertEquals(5, $query->count());
        $this->assertEquals(5, $query->paginate()->total());
    }
}

class User extends Model
{
    protected array $guarded = [];
}
