<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Schema;
use PDO;

class AfterQueryTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('team_id')->nullable();
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('owner_id');
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
        });

        Schema::create('users_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('user_id');
            $table->integer('post_id');
            $table->timestamps();
        });
    }

    public function testAfterQueryOnEloquentBuilder(): void
    {
        AfterQueryUser::create();
        AfterQueryUser::create();

        $afterQueryIds = collect();

        $users = AfterQueryUser::query()
            ->afterQuery(function (Collection $users) use ($afterQueryIds) {
                $afterQueryIds->push(...$users->pluck('id')->all());

                $this->assertContainsOnlyInstancesOf(AfterQueryUser::class, $users);
            })
            ->get();

        $this->assertCount(2, $users);
        $this->assertEqualsCanonicalizing($afterQueryIds->toArray(), $users->pluck('id')->toArray());
    }

    public function testAfterQueryOnBaseBuilder(): void
    {
        AfterQueryUser::create();
        AfterQueryUser::create();

        $afterQueryIds = collect();

        $users = AfterQueryUser::query()
            ->toBase()
            ->afterQuery(function (Collection $users) use ($afterQueryIds) {
                $afterQueryIds->push(...$users->pluck('id')->all());

                foreach ($users as $user) {
                    $this->assertNotInstanceOf(AfterQueryUser::class, $user);
                }
            })
            ->get();

        $this->assertCount(2, $users);
        $this->assertEqualsCanonicalizing($afterQueryIds->toArray(), $users->pluck('id')->toArray());
    }

    public function testAfterQueryOnEloquentCursor(): void
    {
        AfterQueryUser::create();
        AfterQueryUser::create();

        $afterQueryIds = collect();

        $users = AfterQueryUser::query()
            ->afterQuery(function (Collection $users) use ($afterQueryIds) {
                $afterQueryIds->push(...$users->pluck('id')->all());

                $this->assertContainsOnlyInstancesOf(AfterQueryUser::class, $users);
            })
            ->cursor();

        $this->assertCount(2, $users);
        $this->assertEqualsCanonicalizing($afterQueryIds->toArray(), $users->pluck('id')->toArray());
    }

    public function testAfterQueryOnBaseBuilderCursor(): void
    {
        AfterQueryUser::create();
        AfterQueryUser::create();

        $afterQueryIds = collect();

        $users = AfterQueryUser::query()
            ->toBase()
            ->afterQuery(function (Collection $users) use ($afterQueryIds) {
                $afterQueryIds->push(...$users->pluck('id')->all());

                foreach ($users as $user) {
                    $this->assertNotInstanceOf(AfterQueryUser::class, $user);
                }
            })
            ->cursor();

        $this->assertCount(2, $users);
        $this->assertEqualsCanonicalizing($afterQueryIds->toArray(), $users->pluck('id')->toArray());
    }

    public function testAfterQueryOnBaseBuilderCursorDistinguishesNullFromAnEmptyResult(): void
    {
        AfterQueryUser::create(['team_id' => null]);

        $query = AfterQueryUser::query()
            ->toBase()
            ->select('team_id')
            ->fetchUsing(PDO::FETCH_COLUMN);

        $this->assertSame([null], $query->clone()->cursor()->all());
        $this->assertSame(
            [],
            $query->clone()
                ->afterQuery(static fn (Collection $items): Collection => $items->take(0))
                ->cursor()
                ->all()
        );
        $this->assertSame(
            [null],
            $query->clone()
                ->afterQuery(static fn (Collection $items): Collection => new Collection([null]))
                ->cursor()
                ->all()
        );
    }

    public function testAfterQueryOnEloquentPluck(): void
    {
        AfterQueryUser::create();
        AfterQueryUser::create();

        $afterQueryIds = collect();

        $userIds = AfterQueryUser::query()
            ->afterQuery(function (Collection $userIds) use ($afterQueryIds) {
                $afterQueryIds->push(...$userIds->all());

                foreach ($userIds as $userId) {
                    $this->assertIsInt($userId);
                }
            })
            ->pluck('id');

        $this->assertCount(2, $userIds);
        $this->assertEqualsCanonicalizing($afterQueryIds->toArray(), $userIds->toArray());
    }

    public function testAfterQueryOnBaseBuilderPluck(): void
    {
        AfterQueryUser::create();
        AfterQueryUser::create();

        $afterQueryIds = collect();

        $userIds = AfterQueryUser::query()
            ->toBase()
            ->afterQuery(function (Collection $userIds) use ($afterQueryIds) {
                $afterQueryIds->push(...$userIds->all());

                foreach ($userIds as $userId) {
                    $this->assertIsInt((int) $userId);
                }
            })
            ->pluck('id');

        $this->assertCount(2, $userIds);
        $this->assertEqualsCanonicalizing($afterQueryIds->toArray(), $userIds->toArray());
    }

    public function testAfterQueryHookOnBelongsToManyRelationship(): void
    {
        $user = AfterQueryUser::create();
        $firstPost = AfterQueryPost::create();
        $secondPost = AfterQueryPost::create();

        $user->posts()->attach($firstPost);
        $user->posts()->attach($secondPost);

        $afterQueryIds = collect();

        $posts = $user->posts()
            ->afterQuery(function (Collection $posts) use ($afterQueryIds) {
                $afterQueryIds->push(...$posts->pluck('id')->all());

                $this->assertContainsOnlyInstancesOf(AfterQueryPost::class, $posts);
            })
            ->get();

        $this->assertCount(2, $posts);
        $this->assertEqualsCanonicalizing($afterQueryIds->toArray(), $posts->pluck('id')->toArray());
    }

    public function testAfterQueryKeyByOnEagerBelongsToManyRelationship(): void
    {
        $user = AfterQueryUser::create();
        $firstPost = AfterQueryPost::create();
        $secondPost = AfterQueryPost::create();

        $user->posts()->attach($firstPost);
        $user->posts()->attach($secondPost);

        $posts = AfterQueryUser::with('posts')->first()->posts;

        $this->assertEqualsCanonicalizing($posts->pluck('id')->toArray(), $posts->keys()->toArray());
    }

    public function testAfterQueryHookOnHasManyThroughRelationship(): void
    {
        $user = AfterQueryUser::create();
        $team = AfterQueryTeam::create(['owner_id' => $user->id]);

        AfterQueryUser::create(['team_id' => $team->id]);
        AfterQueryUser::create(['team_id' => $team->id]);

        $afterQueryIds = collect();

        $teamMates = $user->teamMates()
            ->afterQuery(function (Collection $teamMates) use ($afterQueryIds) {
                $afterQueryIds->push(...$teamMates->pluck('id')->all());

                $this->assertContainsOnlyInstancesOf(AfterQueryUser::class, $teamMates);
            })
            ->get();

        $this->assertCount(2, $teamMates);
        $this->assertEqualsCanonicalizing($afterQueryIds->toArray(), $teamMates->pluck('id')->toArray());
    }

    public function testAfterQueryOnEloquentBuilderCanAlterReturnedResult(): void
    {
        $firstUser = AfterQueryUser::create();
        $secondUser = AfterQueryUser::create();

        $users = AfterQueryUser::query()
            ->afterQuery(function () {
                return collect(['foo', 'bar']);
            })
            ->get();

        $this->assertEquals(collect(['foo', 'bar']), $users);

        $users = AfterQueryUser::query()
            ->afterQuery(function () {
                return collect(['foo', 'bar']);
            })
            ->pluck('id');

        $this->assertEquals(collect(['foo', 'bar']), $users);

        $users = AfterQueryUser::query()
            ->afterQuery(function ($users) use ($firstUser) {
                return $users->first()->is($firstUser) ? collect(['foo', 'bar']) : collect(['bar', 'foo']);
            })
            ->cursor();

        $this->assertEquals(collect(['foo', 'bar']), $users->collect());

        $users = AfterQueryUser::query()
            ->afterQuery(function ($users) use ($firstUser) {
                return $users->where('id', '!=', $firstUser->id);
            })
            ->cursor();

        $this->assertEquals([$secondUser->id], $users->collect()->pluck('id')->all());

        $firstPost = AfterQueryPost::create();
        $secondPost = AfterQueryPost::create();

        $firstUser->posts()->attach($firstPost);
        $firstUser->posts()->attach($secondPost);

        $posts = $firstUser->posts()
            ->afterQuery(function () {
                return collect(['foo', 'bar']);
            })
            ->get();

        $this->assertEquals(collect(['foo', 'bar']), $posts);

        $user = AfterQueryUser::create();
        $team = AfterQueryTeam::create(['owner_id' => $user->id]);

        AfterQueryUser::create(['team_id' => $team->id]);
        AfterQueryUser::create(['team_id' => $team->id]);

        $teamMates = $user->teamMates()
            ->afterQuery(function () {
                return collect(['foo', 'bar']);
            })
            ->get();

        $this->assertEquals(collect(['foo', 'bar']), $teamMates);
    }

    public function testAfterQueryOnBaseBuilderCanAlterReturnedResult(): void
    {
        $firstUser = AfterQueryUser::create();
        $secondUser = AfterQueryUser::create();

        $users = AfterQueryUser::query()
            ->toBase()
            ->afterQuery(function () {
                return collect(['foo', 'bar']);
            })
            ->get();

        $this->assertEquals(collect(['foo', 'bar']), $users);

        $users = AfterQueryUser::query()
            ->toBase()
            ->afterQuery(function () {
                return collect(['foo', 'bar']);
            })
            ->pluck('id');

        $this->assertEquals(collect(['foo', 'bar']), $users);

        $users = AfterQueryUser::query()
            ->toBase()
            ->afterQuery(function ($users) use ($firstUser) {
                return ((int) $users->first()->id) === $firstUser->id ? collect(['foo', 'bar']) : collect(['bar', 'foo']);
            })
            ->cursor();

        $this->assertEquals(collect(['foo', 'bar']), $users->collect());

        $users = AfterQueryUser::query()
            ->toBase()
            ->afterQuery(function ($users) use ($firstUser) {
                return $users->where('id', '!=', $firstUser->id);
            })
            ->cursor();

        $this->assertEquals([$secondUser->id], $users->collect()->pluck('id')->all());

        $firstPost = AfterQueryPost::create();
        $secondPost = AfterQueryPost::create();

        $firstUser->posts()->attach($firstPost);
        $firstUser->posts()->attach($secondPost);

        $posts = $firstUser->posts()
            ->toBase()
            ->afterQuery(function () {
                return collect(['foo', 'bar']);
            })
            ->get();

        $this->assertEquals(collect(['foo', 'bar']), $posts);

        $user = AfterQueryUser::create();
        $team = AfterQueryTeam::create(['owner_id' => $user->id]);

        AfterQueryUser::create(['team_id' => $team->id]);
        AfterQueryUser::create(['team_id' => $team->id]);

        $teamMates = $user->teamMates()
            ->toBase()
            ->afterQuery(function () {
                return collect(['foo', 'bar']);
            })
            ->get();

        $this->assertEquals(collect(['foo', 'bar']), $teamMates);
    }
}

class AfterQueryUser extends Model
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public bool $timestamps = false;

    /**
     * Get the user's team members.
     */
    public function teamMates(): HasManyThrough
    {
        return $this->hasManyThrough(self::class, AfterQueryTeam::class, 'owner_id', 'team_id');
    }

    /**
     * Get the user's posts keyed by their IDs.
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(AfterQueryPost::class, 'users_posts', 'user_id', 'post_id')
            ->afterQuery(fn (Collection $posts): Collection => $posts->keyBy(fn (AfterQueryPost $post): int => $post->id))
            ->withTimestamps();
    }
}

class AfterQueryTeam extends Model
{
    protected ?string $table = 'teams';

    protected array $guarded = [];

    public bool $timestamps = false;

    /**
     * Get the team's members.
     */
    public function members(): HasMany
    {
        return $this->hasMany(AfterQueryUser::class, 'team_id');
    }
}

class AfterQueryPost extends Model
{
    protected ?string $table = 'posts';

    protected array $guarded = [];

    public bool $timestamps = false;
}
