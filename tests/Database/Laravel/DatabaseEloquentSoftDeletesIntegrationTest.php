<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\DatabaseEloquentSoftDeletesIntegrationTest;

use BadMethodCallException;
use Exception;
use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Eloquent\SoftDeletingScope;
use Hypervel\Database\Query\Builder;
use Hypervel\Pagination\CursorPaginator;
use Hypervel\Pagination\Paginator;
use Hypervel\Support\Carbon;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Mockery\MockInterface;

/**
 * @internal
 * @coversNothing
 */
class DatabaseEloquentSoftDeletesIntegrationTest extends TestCase
{
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

    /**
     * Setup the database schema.
     */
    public function createSchema()
    {
        $this->schema()->create('users', function ($table) {
            $table->increments('id');
            $table->integer('user_id')->nullable(); // circular reference to parent User
            $table->integer('group_id')->nullable();
            $table->string('email')->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        $this->schema()->create('posts', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('title');
            $table->integer('priority')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        $this->schema()->create('comments', function ($table) {
            $table->increments('id');
            $table->integer('owner_id')->nullable();
            $table->string('owner_type')->nullable();
            $table->integer('post_id');
            $table->string('body');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->schema()->create('addresses', function ($table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->string('address');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->schema()->create('groups', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Tear down the database schema.
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow(null);

        $this->schema()->drop('users');
        $this->schema()->drop('posts');
        $this->schema()->drop('comments');

        parent::tearDown();
    }

    /**
     * Tests...
     */
    public function testSoftDeletesAreNotRetrieved()
    {
        $this->createUsers();

        $users = User::all();

        $this->assertCount(1, $users);
        $this->assertEquals(2, $users->first()->id);
        $this->assertNull(User::find(1));
    }

    public function testSoftDeletesAreNotRetrievedFromBaseQuery()
    {
        $this->createUsers();

        $query = User::query()->toBase();

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertCount(1, $query->get());
    }

    public function testSoftDeletesAreNotRetrievedFromRelationshipBaseQuery()
    {
        [, $abigail] = $this->createUsers();

        $abigail->posts()->create(['title' => 'Foo']);
        $abigail->posts()->create(['title' => 'Bar'])->delete();

        $query = $abigail->posts()->toBase();

        $this->assertInstanceOf(Builder::class, $query);
        $this->assertCount(1, $query->get());
    }

    public function testSoftDeletesAreNotRetrievedFromBuilderHelpers()
    {
        $this->createUsers();

        $count = 0;
        $query = User::query();
        $query->chunk(2, function ($user) use (&$count) {
            $count += count($user);
        });
        $this->assertEquals(1, $count);

        $query = User::query();
        $this->assertCount(1, $query->pluck('email')->all());

        Paginator::currentPageResolver(function () {
            return 1;
        });

        CursorPaginator::currentCursorResolver(function () {
            return null;
        });

        $query = User::query();
        $this->assertCount(1, $query->paginate(2)->all());

        $query = User::query();
        $this->assertCount(1, $query->simplePaginate(2)->all());

        $query = User::query();
        $this->assertCount(1, $query->cursorPaginate(2)->all());

        $this->assertEquals(0, User::where('email', 'taylorotwell@gmail.com')->increment('id'));
        $this->assertEquals(0, User::where('email', 'taylorotwell@gmail.com')->decrement('id'));
    }

    public function testWithTrashedReturnsAllRecords()
    {
        $this->createUsers();

        $this->assertCount(2, User::withTrashed()->get());
        $this->assertInstanceOf(Eloquent::class, User::withTrashed()->find(1));
    }

    public function testWithTrashedAcceptsAnArgument()
    {
        $this->createUsers();

        $this->assertCount(1, User::withTrashed(false)->get());
        $this->assertCount(2, User::withTrashed(true)->get());
    }

    public function testDeleteSetsDeletedColumn()
    {
        $this->createUsers();

        $this->assertInstanceOf(Carbon::class, User::withTrashed()->find(1)->deleted_at);
        $this->assertNull(User::find(2)->deleted_at);
    }

    public function testForceDeleteActuallyDeletesRecords()
    {
        $this->createUsers();
        User::find(2)->forceDelete();

        $users = User::withTrashed()->get();

        $this->assertCount(1, $users);
        $this->assertEquals(1, $users->first()->id);
    }

    public function testForceDeleteUpdateExistsProperty()
    {
        $this->createUsers();
        $user = User::find(2);

        $this->assertTrue($user->exists);

        $user->forceDelete();

        $this->assertFalse($user->exists);
    }

    public function testForceDeleteDoesntUpdateExistsPropertyIfFailed()
    {
        $user = new class extends User {
            public bool $exists = true;

            public function newModelQuery(): \Hypervel\Database\Eloquent\Builder
            {
                return m::spy(parent::newModelQuery(), function (MockInterface $mock) {
                    $mock->shouldReceive('forceDelete')->andThrow(new Exception());
                });
            }
        };

        $this->assertTrue($user->exists);

        try {
            $user->forceDelete();
        } catch (Exception) {
        }

        $this->assertTrue($user->exists);
    }

    public function testForceDestroyFullyDeletesRecord()
    {
        $this->createUsers();
        $deleted = User::forceDestroy(2);

        $this->assertSame(1, $deleted);

        $users = User::withTrashed()->get();

        $this->assertCount(1, $users);
        $this->assertEquals(1, $users->first()->id);
        $this->assertNull(User::find(2));
    }

    public function testForceDestroyDeletesAlreadyDeletedRecord()
    {
        $this->createUsers();
        $deleted = User::forceDestroy(1);

        $this->assertSame(1, $deleted);

        $users = User::withTrashed()->get();

        $this->assertCount(1, $users);
        $this->assertEquals(2, $users->first()->id);
        $this->assertNull(User::find(1));
    }

    public function testForceDestroyDeletesMultipleRecords()
    {
        $this->createUsers();
        $deleted = User::forceDestroy([1, 2]);

        $this->assertSame(2, $deleted);

        $this->assertTrue(User::withTrashed()->get()->isEmpty());
    }

    public function testForceDestroyDeletesRecordsFromCollection()
    {
        $this->createUsers();
        $deleted = User::forceDestroy(collect([1, 2]));

        $this->assertSame(2, $deleted);

        $this->assertTrue(User::withTrashed()->get()->isEmpty());
    }

    public function testForceDestroyDeletesRecordsFromEloquentCollection()
    {
        $this->createUsers();
        $deleted = User::forceDestroy(User::all());

        $this->assertSame(1, $deleted);

        $users = User::withTrashed()->get();

        $this->assertCount(1, $users);
        $this->assertEquals(1, $users->first()->id);
        $this->assertNull(User::find(2));
    }

    public function testRestoreRestoresRecords()
    {
        $this->createUsers();
        $taylor = User::withTrashed()->find(1);

        $this->assertTrue($taylor->trashed());

        $taylor->restore();

        $users = User::all();

        $this->assertCount(2, $users);
        $this->assertNull($users->find(1)->deleted_at);
        $this->assertNull($users->find(2)->deleted_at);
    }

    public function testOnlyTrashedOnlyReturnsTrashedRecords()
    {
        $this->createUsers();

        $users = User::onlyTrashed()->get();

        $this->assertCount(1, $users);
        $this->assertEquals(1, $users->first()->id);
    }

    public function testOnlyWithoutTrashedOnlyReturnsTrashedRecords()
    {
        $this->createUsers();

        $users = User::withoutTrashed()->get();

        $this->assertCount(1, $users);
        $this->assertEquals(2, $users->first()->id);

        $users = User::withTrashed()->withoutTrashed()->get();

        $this->assertCount(1, $users);
        $this->assertEquals(2, $users->first()->id);
    }

    public function testFirstOrNew()
    {
        $this->createUsers();

        $result = User::firstOrNew(['email' => 'taylorotwell@gmail.com']);
        $this->assertNull($result->id);

        $result = User::withTrashed()->firstOrNew(['email' => 'taylorotwell@gmail.com']);
        $this->assertEquals(1, $result->id);
    }

    public function testFindOrNew()
    {
        $this->createUsers();

        $result = User::findOrNew(1);
        $this->assertNull($result->id);

        $result = User::withTrashed()->findOrNew(1);
        $this->assertEquals(1, $result->id);
    }

    public function testFirstOrCreate()
    {
        $this->createUsers();

        $result = User::withTrashed()->firstOrCreate(['email' => 'taylorotwell@gmail.com']);
        $this->assertSame('taylorotwell@gmail.com', $result->email);
        $this->assertCount(1, User::all());

        $result = User::firstOrCreate(['email' => 'foo@bar.com']);
        $this->assertSame('foo@bar.com', $result->email);
        $this->assertCount(2, User::all());
        $this->assertCount(3, User::withTrashed()->get());
    }

    public function testCreateOrFirst()
    {
        $this->createUsers();

        $result = User::withTrashed()->createOrFirst(['email' => 'taylorotwell@gmail.com']);
        $this->assertSame('taylorotwell@gmail.com', $result->email);
        $this->assertCount(1, User::all());

        $result = User::createOrFirst(['email' => 'foo@bar.com']);
        $this->assertSame('foo@bar.com', $result->email);
        $this->assertCount(2, User::all());
        $this->assertCount(3, User::withTrashed()->get());
    }

    /**
     * @throws Exception
     */
    public function testUpdateModelAfterSoftDeleting()
    {
        Carbon::setTestNow($now = Carbon::now());
        $this->createUsers();

        /** @var User $userModel */
        $userModel = User::find(2);
        $userModel->delete();
        $this->assertEquals($now->toDateTimeString(), $userModel->getOriginal('deleted_at'));
        $this->assertNull(User::find(2));
        $this->assertEquals($userModel, User::withTrashed()->find(2));
    }

    /**
     * @throws Exception
     */
    public function testRestoreAfterSoftDelete()
    {
        $this->createUsers();

        /** @var User $userModel */
        $userModel = User::find(2);
        $userModel->delete();
        $userModel->restore();

        $this->assertEquals($userModel->id, User::find(2)->id);
    }

    /**
     * @throws Exception
     */
    public function testSoftDeleteAfterRestoring()
    {
        $this->createUsers();

        /** @var User $userModel */
        $userModel = User::withTrashed()->find(1);
        $userModel->restore();
        $this->assertEquals($userModel->deleted_at, User::find(1)->deleted_at);
        $this->assertEquals($userModel->getOriginal('deleted_at'), User::find(1)->deleted_at);
        $userModel->delete();
        $this->assertNull(User::find(1));
        $this->assertEquals($userModel->deleted_at, User::withTrashed()->find(1)->deleted_at);
        $this->assertEquals($userModel->getOriginal('deleted_at'), User::withTrashed()->find(1)->deleted_at);
    }

    public function testModifyingBeforeSoftDeletingAndRestoring()
    {
        $this->createUsers();

        /** @var User $userModel */
        $userModel = User::find(2);
        $userModel->email = 'foo@bar.com';
        $userModel->delete();
        $userModel->restore();

        $this->assertEquals($userModel->id, User::find(2)->id);
        $this->assertSame('foo@bar.com', User::find(2)->email);
    }

    public function testUpdateOrCreate()
    {
        $this->createUsers();

        $result = User::updateOrCreate(['email' => 'foo@bar.com'], ['email' => 'bar@baz.com']);
        $this->assertSame('bar@baz.com', $result->email);
        $this->assertCount(2, User::all());

        $result = User::withTrashed()->updateOrCreate(['email' => 'taylorotwell@gmail.com'], ['email' => 'foo@bar.com']);
        $this->assertSame('foo@bar.com', $result->email);
        $this->assertCount(2, User::all());
        $this->assertCount(3, User::withTrashed()->get());
    }

    public function testHasOneRelationshipCanBeSoftDeleted()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $abigail->address()->create(['address' => 'Laravel avenue 43']);

        // delete on builder
        $abigail->address()->delete();

        $abigail = $abigail->fresh();

        $this->assertNull($abigail->address);
        $this->assertSame('Laravel avenue 43', $abigail->address()->withTrashed()->first()->address);

        // restore
        $abigail->address()->withTrashed()->restore();

        $abigail = $abigail->fresh();

        $this->assertSame('Laravel avenue 43', $abigail->address->address);

        // delete on model
        $abigail->address->delete();

        $abigail = $abigail->fresh();

        $this->assertNull($abigail->address);
        $this->assertSame('Laravel avenue 43', $abigail->address()->withTrashed()->first()->address);

        // force delete
        $abigail->address()->withTrashed()->forceDelete();

        $abigail = $abigail->fresh();

        $this->assertNull($abigail->address);
    }

    public function testBelongsToRelationshipCanBeSoftDeleted()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $group = Group::create(['name' => 'admin']);
        $abigail->group()->associate($group);
        $abigail->save();

        // delete on builder
        $abigail->group()->delete();

        $abigail = $abigail->fresh();

        $this->assertNull($abigail->group);
        $this->assertSame('admin', $abigail->group()->withTrashed()->first()->name);

        // restore
        $abigail->group()->withTrashed()->restore();

        $abigail = $abigail->fresh();

        $this->assertSame('admin', $abigail->group->name);

        // delete on model
        $abigail->group->delete();

        $abigail = $abigail->fresh();

        $this->assertNull($abigail->group);
        $this->assertSame('admin', $abigail->group()->withTrashed()->first()->name);

        // force delete
        $abigail->group()->withTrashed()->forceDelete();

        $abigail = $abigail->fresh();

        $this->assertNull($abigail->group()->withTrashed()->first());
    }

    public function testHasManyRelationshipCanBeSoftDeleted()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $abigail->posts()->create(['title' => 'First Title']);
        $abigail->posts()->create(['title' => 'Second Title']);

        // delete on builder
        $abigail->posts()->where('title', 'Second Title')->delete();

        $abigail = $abigail->fresh();

        $this->assertCount(1, $abigail->posts);
        $this->assertSame('First Title', $abigail->posts->first()->title);
        $this->assertCount(2, $abigail->posts()->withTrashed()->get());

        // restore
        $abigail->posts()->withTrashed()->restore();

        $abigail = $abigail->fresh();

        $this->assertCount(2, $abigail->posts);

        // force delete
        $abigail->posts()->where('title', 'Second Title')->forceDelete();

        $abigail = $abigail->fresh();

        $this->assertCount(1, $abigail->posts);
        $this->assertCount(1, $abigail->posts()->withTrashed()->get());
    }

    public function testRelationToSqlAppliesSoftDelete()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();

        $this->assertSame(
            'select * from "posts" where "posts"."user_id" = ? and "posts"."user_id" is not null and "posts"."deleted_at" is null',
            $abigail->posts()->toSql()
        );
    }

    public function testRelationExistsAndDoesntExistHonorsSoftDelete()
    {
        $this->createUsers();
        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();

        // 'exists' should return true before soft delete
        $abigail->posts()->create(['title' => 'First Title']);
        $this->assertTrue($abigail->posts()->exists());
        $this->assertFalse($abigail->posts()->doesntExist());

        // 'exists' should return false after soft delete
        $abigail->posts()->first()->delete();
        $this->assertFalse($abigail->posts()->exists());
        $this->assertTrue($abigail->posts()->doesntExist());

        // 'exists' should return true after restore
        $abigail->posts()->withTrashed()->restore();
        $this->assertTrue($abigail->posts()->exists());
        $this->assertFalse($abigail->posts()->doesntExist());

        // 'exists' should return false after a force delete
        $abigail->posts()->first()->forceDelete();
        $this->assertFalse($abigail->posts()->exists());
        $this->assertTrue($abigail->posts()->doesntExist());
    }

    public function testRelationCountHonorsSoftDelete()
    {
        $this->createUsers();
        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();

        // check count before soft delete
        $abigail->posts()->create(['title' => 'First Title']);
        $abigail->posts()->create(['title' => 'Second Title']);
        $this->assertEquals(2, $abigail->posts()->count());

        // check count after soft delete
        $abigail->posts()->where('title', 'Second Title')->delete();
        $this->assertEquals(1, $abigail->posts()->count());

        // check count after restore
        $abigail->posts()->withTrashed()->restore();
        $this->assertEquals(2, $abigail->posts()->count());

        // check count after a force delete
        $abigail->posts()->where('title', 'Second Title')->forceDelete();
        $this->assertEquals(1, $abigail->posts()->count());
    }

    public function testRelationAggregatesHonorsSoftDelete()
    {
        $this->createUsers();
        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();

        // check aggregates before soft delete
        $abigail->posts()->create(['title' => 'First Title', 'priority' => 2]);
        $abigail->posts()->create(['title' => 'Second Title', 'priority' => 4]);
        $abigail->posts()->create(['title' => 'Third Title', 'priority' => 6]);
        $this->assertEquals(2, $abigail->posts()->min('priority'));
        $this->assertEquals(6, $abigail->posts()->max('priority'));
        $this->assertEquals(12, $abigail->posts()->sum('priority'));
        $this->assertEquals(4, $abigail->posts()->avg('priority'));

        // check aggregates after soft delete
        $abigail->posts()->where('title', 'First Title')->delete();
        $this->assertEquals(4, $abigail->posts()->min('priority'));
        $this->assertEquals(6, $abigail->posts()->max('priority'));
        $this->assertEquals(10, $abigail->posts()->sum('priority'));
        $this->assertEquals(5, $abigail->posts()->avg('priority'));

        // check aggregates after restore
        $abigail->posts()->withTrashed()->restore();
        $this->assertEquals(2, $abigail->posts()->min('priority'));
        $this->assertEquals(6, $abigail->posts()->max('priority'));
        $this->assertEquals(12, $abigail->posts()->sum('priority'));
        $this->assertEquals(4, $abigail->posts()->avg('priority'));

        // check aggregates after a force delete
        $abigail->posts()->where('title', 'Third Title')->forceDelete();
        $this->assertEquals(2, $abigail->posts()->min('priority'));
        $this->assertEquals(4, $abigail->posts()->max('priority'));
        $this->assertEquals(6, $abigail->posts()->sum('priority'));
        $this->assertEquals(3, $abigail->posts()->avg('priority'));
    }

    public function testSoftDeleteIsAppliedToNewQuery()
    {
        $query = (new User())->newQuery();
        $this->assertSame('select * from "users" where "users"."deleted_at" is null', $query->toSql());
    }

    public function testSecondLevelRelationshipCanBeSoftDeleted()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $post = $abigail->posts()->create(['title' => 'First Title']);
        $post->comments()->create(['body' => 'Comment Body']);

        $abigail->posts()->first()->comments()->delete();

        $abigail = $abigail->fresh();

        $this->assertCount(0, $abigail->posts()->first()->comments);
        $this->assertCount(1, $abigail->posts()->first()->comments()->withTrashed()->get());
    }

    public function testWhereHasWithDeletedRelationship()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $post = $abigail->posts()->create(['title' => 'First Title']);

        $users = User::where('email', 'taylorotwell@gmail.com')->has('posts')->get();
        $this->assertCount(0, $users);

        $users = User::where('email', 'abigailotwell@gmail.com')->has('posts')->get();
        $this->assertCount(1, $users);

        $users = User::where('email', 'doesnt@exist.com')->orHas('posts')->get();
        $this->assertCount(1, $users);

        $users = User::whereHas('posts', function ($query) {
            $query->where('title', 'First Title');
        })->get();
        $this->assertCount(1, $users);

        $users = User::whereHas('posts', function ($query) {
            $query->where('title', 'Another Title');
        })->get();
        $this->assertCount(0, $users);

        $users = User::where('email', 'doesnt@exist.com')->orWhereHas('posts', function ($query) {
            $query->where('title', 'First Title');
        })->get();
        $this->assertCount(1, $users);

        // With Post Deleted...

        $post->delete();
        $users = User::has('posts')->get();
        $this->assertCount(0, $users);
    }

    public function testWhereHasWithNestedDeletedRelationshipAndOnlyTrashedCondition()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $post = $abigail->posts()->create(['title' => 'First Title']);
        $post->delete();

        $users = User::has('posts')->get();
        $this->assertCount(0, $users);

        $users = User::whereHas('posts', function ($q) {
            $q->onlyTrashed();
        })->get();
        $this->assertCount(1, $users);

        $users = User::whereHas('posts', function ($q) {
            $q->withTrashed();
        })->get();
        $this->assertCount(1, $users);
    }

    public function testWhereHasWithNestedDeletedRelationship()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $post = $abigail->posts()->create(['title' => 'First Title']);
        $comment = $post->comments()->create(['body' => 'Comment Body']);
        $comment->delete();

        $users = User::has('posts.comments')->get();
        $this->assertCount(0, $users);

        $users = User::doesntHave('posts.comments')->get();
        $this->assertCount(1, $users);
    }

    public function testWhereDoesntHaveWithNestedDeletedRelationship()
    {
        $this->createUsers();

        $users = User::doesntHave('posts.comments')->get();
        $this->assertCount(1, $users);
    }

    public function testWhereHasWithNestedDeletedRelationshipAndWithTrashedCondition()
    {
        $this->createUsers();

        $abigail = UserWithTrashedPosts::where('email', 'abigailotwell@gmail.com')->first();
        $post = $abigail->posts()->create(['title' => 'First Title']);
        $post->delete();

        $users = UserWithTrashedPosts::has('posts')->get();
        $this->assertCount(1, $users);
    }

    public function testWithCountWithNestedDeletedRelationshipAndOnlyTrashedCondition()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $post1 = $abigail->posts()->create(['title' => 'First Title']);
        $post1->delete();
        $abigail->posts()->create(['title' => 'Second Title']);
        $abigail->posts()->create(['title' => 'Third Title']);

        $user = User::withCount('posts')->orderBy('postsCount', 'desc')->first();
        $this->assertEquals(2, $user->posts_count);

        $user = User::withCount(['posts' => function ($q) {
            $q->onlyTrashed();
        }])->orderBy('postsCount', 'desc')->first();
        $this->assertEquals(1, $user->posts_count);

        $user = User::withCount(['posts' => function ($q) {
            $q->withTrashed();
        }])->orderBy('postsCount', 'desc')->first();
        $this->assertEquals(3, $user->posts_count);

        $user = User::withCount(['posts' => function ($q) {
            $q->withTrashed()->where('title', 'First Title');
        }])->orderBy('postsCount', 'desc')->first();
        $this->assertEquals(1, $user->posts_count);

        $user = User::withCount(['posts' => function ($q) {
            $q->where('title', 'First Title');
        }])->orderBy('postsCount', 'desc')->first();
        $this->assertEquals(0, $user->posts_count);
    }

    public function testOrWhereWithSoftDeleteConstraint()
    {
        $this->createUsers();

        $users = User::where('email', 'taylorotwell@gmail.com')->orWhere('email', 'abigailotwell@gmail.com');
        $this->assertEquals(['abigailotwell@gmail.com'], $users->pluck('email')->all());
    }

    public function testMorphToWithTrashed()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $post1 = $abigail->posts()->create(['title' => 'First Title']);
        $post1->comments()->create([
            'body' => 'Comment Body',
            'owner_type' => User::class,
            'owner_id' => $abigail->id,
        ]);

        $abigail->delete();

        $comment = CommentWithTrashed::with(['owner' => function ($q) {
            $q->withoutGlobalScope(SoftDeletingScope::class);
        }])->first();

        $this->assertEquals($abigail->email, $comment->owner->email);

        $comment = CommentWithTrashed::with(['owner' => function ($q) {
            $q->withTrashed();
        }])->first();

        $this->assertEquals($abigail->email, $comment->owner->email);

        $comment = CommentWithoutSoftDelete::with(['owner' => function ($q) {
            $q->withTrashed();
        }])->first();

        $this->assertEquals($abigail->email, $comment->owner->email);
    }

    public function testMorphToWithBadMethodCall()
    {
        $this->expectException(BadMethodCallException::class);

        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $post1 = $abigail->posts()->create(['title' => 'First Title']);

        $post1->comments()->create([
            'body' => 'Comment Body',
            'owner_type' => User::class,
            'owner_id' => $abigail->id,
        ]);

        CommentWithoutSoftDelete::with(['owner' => function ($q) {
            $q->thisMethodDoesNotExist();
        }])->first();
    }

    public function testMorphToWithConstraints()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $post1 = $abigail->posts()->create(['title' => 'First Title']);
        $post1->comments()->create([
            'body' => 'Comment Body',
            'owner_type' => User::class,
            'owner_id' => $abigail->id,
        ]);

        $comment = CommentWithTrashed::with(['owner' => function ($q) {
            $q->where('email', 'taylorotwell@gmail.com');
        }])->first();

        $this->assertNull($comment->owner);
    }

    public function testMorphToWithoutConstraints()
    {
        $this->createUsers();

        $abigail = User::where('email', 'abigailotwell@gmail.com')->first();
        $post1 = $abigail->posts()->create(['title' => 'First Title']);
        $post1->comments()->create([
            'body' => 'Comment Body',
            'owner_type' => User::class,
            'owner_id' => $abigail->id,
        ]);

        $comment = CommentWithTrashed::with('owner')->first();

        $this->assertEquals($abigail->email, $comment->owner->email);

        $abigail->delete();
        $comment = CommentWithTrashed::with('owner')->first();

        $this->assertNull($comment->owner);
    }

    public function testMorphToNonSoftDeletingModel()
    {
        $taylor = UserWithoutSoftDelete::create(['id' => 1, 'email' => 'taylorotwell@gmail.com']);
        $post1 = $taylor->posts()->create(['title' => 'First Title']);
        $post1->comments()->create([
            'body' => 'Comment Body',
            'owner_type' => UserWithoutSoftDelete::class,
            'owner_id' => $taylor->id,
        ]);

        $comment = CommentWithTrashed::with('owner')->first();

        $this->assertEquals($taylor->email, $comment->owner->email);

        $taylor->delete();
        $comment = CommentWithTrashed::with('owner')->first();

        $this->assertNull($comment->owner);
    }

    public function testSelfReferencingRelationshipWithSoftDeletes()
    {
        // https://github.com/laravel/framework/issues/42075
        [$taylor, $abigail] = $this->createUsers();

        $this->assertCount(1, $abigail->self_referencing);
        $this->assertTrue($abigail->self_referencing->first()->is($taylor));

        $this->assertCount(0, $taylor->self_referencing);
        $this->assertEquals(1, User::whereHas('self_referencing')->count());
    }

    /**
     * Helpers...
     *
     * @return User[]
     */
    protected function createUsers()
    {
        $taylor = User::create(['id' => 1, 'email' => 'taylorotwell@gmail.com', 'user_id' => 2]);
        $abigail = User::create(['id' => 2, 'email' => 'abigailotwell@gmail.com']);

        $taylor->delete();

        return [$taylor, $abigail];
    }

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\Connection
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

/**
 * Eloquent Models...
 */
class UserWithoutSoftDelete extends Eloquent
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }
}

/**
 * Eloquent Models...
 */
class User extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'users';

    protected array $guarded = [];

    public function self_referencing()
    {
        return $this->hasMany(User::class, 'user_id')->onlyTrashed();
    }

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id');
    }

    public function address()
    {
        return $this->hasOne(Address::class, 'user_id');
    }

    public function group()
    {
        return $this->belongsTo(Group::class, 'group_id');
    }
}

class UserWithTrashedPosts extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'users';

    protected array $guarded = [];

    public function posts()
    {
        return $this->hasMany(Post::class, 'user_id')->withTrashed();
    }
}

/**
 * Eloquent Models...
 */
class Post extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'posts';

    protected array $guarded = [];

    public function comments()
    {
        return $this->hasMany(Comment::class, 'post_id');
    }
}

/**
 * Eloquent Models...
 */
class CommentWithoutSoftDelete extends Eloquent
{
    protected ?string $table = 'comments';

    protected array $guarded = [];

    public function owner()
    {
        return $this->morphTo();
    }
}

/**
 * Eloquent Models...
 */
class Comment extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'comments';

    protected array $guarded = [];

    public function owner()
    {
        return $this->morphTo();
    }
}

class CommentWithTrashed extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'comments';

    protected array $guarded = [];

    public function owner()
    {
        return $this->morphTo();
    }
}

/**
 * Eloquent Models...
 */
class Address extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'addresses';

    protected array $guarded = [];
}

/**
 * Eloquent Models...
 */
class Group extends Eloquent
{
    use SoftDeletes;

    protected ?string $table = 'groups';

    protected array $guarded = [];

    public function users()
    {
        $this->hasMany(User::class);
    }
}
