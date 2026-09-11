<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\EloquentHasManyThroughTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\HasMany;
use Hypervel\Database\Eloquent\Relations\HasManyThrough;
use Hypervel\Database\Eloquent\Relations\HasOneThrough;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Schema;
use Hypervel\Support\Str;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

class EloquentHasManyThroughTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug')->nullable();
            $table->integer('team_id')->nullable();
            $table->string('name');
        });

        Schema::create('teams', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('owner_id')->nullable();
            $table->string('owner_slug')->nullable();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('parent_id')->nullable();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->integer('category_id');
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id');
            $table->string('title')->unique();
            $table->timestamps();
        });
    }

    public function testBasicCreateAndRetrieve(): void
    {
        $user = User::create(['name' => Str::random()]);

        $team1 = Team::create(['owner_id' => $user->id]);
        $team2 = Team::create(['owner_id' => $user->id]);

        $mate1 = User::create(['name' => 'John', 'team_id' => $team1->id]);
        $mate2 = User::create(['name' => 'Jack', 'team_id' => $team2->id, 'slug' => null]);

        User::create(['name' => Str::random()]);

        $this->assertEquals([$mate1->id, $mate2->id], $user->teamMates->pluck('id')->toArray());
        $this->assertEquals([$mate1->id, $mate2->id], $user->teamMatesWithPendingRelation->pluck('id')->toArray());
        $this->assertEquals([$user->id], User::has('teamMates')->pluck('id')->toArray());
        $this->assertEquals([$user->id], User::has('teamMatesWithPendingRelation')->pluck('id')->toArray());

        $result = $user->teamMates()->first();
        $this->assertEquals(
            $mate1->refresh()->getAttributes() + ['hypervel_through_key' => '1'],
            $result->getAttributes()
        );

        $result = $user->teamMatesWithPendingRelation()->first();
        $this->assertEquals(
            $mate1->refresh()->getAttributes() + ['hypervel_through_key' => '1'],
            $result->getAttributes()
        );

        $result = $user->teamMates()->firstWhere('name', 'Jack');
        $this->assertEquals(
            $mate2->refresh()->getAttributes() + ['hypervel_through_key' => '1'],
            $result->getAttributes()
        );

        $result = $user->teamMatesWithPendingRelation()->firstWhere('name', 'Jack');
        $this->assertEquals(
            $mate2->refresh()->getAttributes() + ['hypervel_through_key' => '1'],
            $result->getAttributes()
        );
    }

    public function testGlobalScopeColumns(): void
    {
        $user = User::create(['name' => Str::random()]);

        $team1 = Team::create(['owner_id' => $user->id]);

        User::create(['name' => Str::random(), 'team_id' => $team1->id]);

        $teamMates = $user->teamMatesWithGlobalScope;
        $this->assertEquals(['id' => 2, 'hypervel_through_key' => 1], $teamMates[0]->getAttributes());

        $teamMates = $user->teamMatesWithGlobalScopeWithPendingRelation;
        $this->assertEquals(['id' => 2, 'hypervel_through_key' => 1], $teamMates[0]->getAttributes());
    }

    public function testHasSelf(): void
    {
        $user = User::create(['name' => Str::random()]);

        $team = Team::create(['owner_id' => $user->id]);

        User::create(['name' => Str::random(), 'team_id' => $team->id]);

        $users = User::has('teamMates')->get();
        $this->assertCount(1, $users);

        $users = User::has('teamMatesWithPendingRelation')->get();
        $this->assertCount(1, $users);
    }

    public function testHasSelfCustomOwnerKey(): void
    {
        $user = User::create(['slug' => Str::random(), 'name' => Str::random()]);

        $team = Team::create(['owner_slug' => $user->slug]);

        User::create(['name' => Str::random(), 'team_id' => $team->id]);

        $users = User::has('teamMatesBySlug')->get();
        $this->assertCount(1, $users);

        $users = User::has('teamMatesBySlugWithPendingRelationship')->get();
        $this->assertCount(1, $users);
    }

    public function testHasSameParentAndThroughParentTable(): void
    {
        Category::create();
        Category::create();
        Category::create(['parent_id' => 1]);
        Category::create(['parent_id' => 2])->delete();

        Product::create(['category_id' => 3]);
        Product::create(['category_id' => 4]);

        $categories = Category::has('subProducts')->get();

        $this->assertEquals([1], $categories->pluck('id')->all());
    }

    public function testFirstOrNewOnMissingRecord(): void
    {
        $taylor = User::create(['name' => 'Taylor', 'slug' => 'taylor']);
        $team = Team::create(['owner_id' => $taylor->id]);

        $user1 = $taylor->teamMates()->firstOrNew(
            ['slug' => 'tony'],
            ['name' => 'Tony', 'team_id' => $team->id],
        );

        $this->assertFalse($user1->exists);
        $this->assertEquals($team->id, $user1->team_id);
        $this->assertSame('tony', $user1->slug);
        $this->assertSame('Tony', $user1->name);
    }

    public function testFirstOrNewAcceptsClosureValuesOnMissingRecord(): void
    {
        $taylor = User::create(['name' => 'Taylor', 'slug' => 'taylor']);
        $team = Team::create(['owner_id' => $taylor->id]);
        $callCount = 0;

        $user = $taylor->teamMates()->firstOrNew(['slug' => 'tony'], function () use (&$callCount, $team) {
            ++$callCount;

            return ['name' => 'Tony', 'team_id' => $team->id];
        });

        $this->assertSame(1, $callCount);
        $this->assertFalse($user->exists);
        $this->assertEquals($team->id, $user->team_id);
        $this->assertSame('tony', $user->slug);
        $this->assertSame('Tony', $user->name);
    }

    public function testFirstOrNewWhenRecordExists(): void
    {
        $taylor = User::create(['name' => 'Taylor', 'slug' => 'taylor']);
        $team = Team::create(['owner_id' => $taylor->id]);
        $existingTony = $team->members()->create(['name' => 'Tony Messias', 'slug' => 'tony']);

        $newTony = $taylor->teamMates()->firstOrNew(
            ['slug' => 'tony'],
            ['name' => 'Tony', 'team_id' => $team->id],
        );

        $this->assertTrue($newTony->exists);
        $this->assertEquals($team->id, $newTony->team_id);
        $this->assertSame('tony', $newTony->slug);
        $this->assertSame('Tony Messias', $newTony->name);

        $this->assertTrue($existingTony->is($newTony));
        $this->assertSame('tony', $existingTony->refresh()->slug);
        $this->assertSame('Tony Messias', $existingTony->name);
    }

    public function testFirstOrNewDoesNotInvokeClosureValuesWhenRecordExists(): void
    {
        $taylor = User::create(['name' => 'Taylor', 'slug' => 'taylor']);
        $team = Team::create(['owner_id' => $taylor->id]);
        $existingTony = $team->members()->create(['name' => 'Tony Messias', 'slug' => 'tony']);
        $callCount = 0;

        $user = $taylor->teamMates()->firstOrNew(['slug' => 'tony'], function () use (&$callCount) {
            ++$callCount;

            return ['name' => 'Tony'];
        });

        $this->assertSame(0, $callCount);
        $this->assertTrue($existingTony->is($user));
        $this->assertSame('Tony Messias', $user->name);
    }

    public function testFirstOrCreateWhenModelDoesntExist(): void
    {
        $owner = User::create(['name' => 'Taylor']);
        Team::create(['owner_id' => $owner->id]);

        $mate = $owner->teamMates()->firstOrCreate(['slug' => 'adam'], ['name' => 'Adam']);

        $this->assertTrue($mate->wasRecentlyCreated);
        $this->assertNull($mate->team_id);
        $this->assertEquals('Adam', $mate->name);
        $this->assertEquals('adam', $mate->slug);
    }

    public function testFirstOrCreateWhenModelExists(): void
    {
        $owner = User::create(['name' => 'Taylor']);
        $team = Team::create(['owner_id' => $owner->id]);

        $team->members()->create(['slug' => 'adam', 'name' => 'Adam Wathan']);

        $mate = $owner->teamMates()->firstOrCreate(['slug' => 'adam'], ['name' => 'Adam']);

        $this->assertFalse($mate->wasRecentlyCreated);
        $this->assertNotNull($mate->team_id);
        $this->assertTrue($team->is($mate->team));
        $this->assertEquals('Adam Wathan', $mate->name);
        $this->assertEquals('adam', $mate->slug);
    }

    public function testFirstOrCreateRegressionIssue(): void
    {
        $team1 = Team::create();
        $team2 = Team::create();

        $jane = $team2->members()->create(['name' => 'Jane', 'slug' => 'jane']);
        $john = $team1->members()->create(['name' => 'John', 'slug' => 'john']);

        $taylor = User::create(['name' => 'Taylor']);
        $team1->update(['owner_id' => $taylor->id]);

        $newJohn = $taylor->teamMates()->firstOrCreate(
            ['slug' => 'john'],
            ['name' => 'John Doe'],
        );

        $this->assertFalse($newJohn->wasRecentlyCreated);
        $this->assertTrue($john->is($newJohn));
        $this->assertEquals('john', $newJohn->refresh()->slug);
        $this->assertEquals('John', $newJohn->name);

        $this->assertSame('john', $john->refresh()->slug);
        $this->assertSame('John', $john->name);
        $this->assertSame('jane', $jane->refresh()->slug);
        $this->assertSame('Jane', $jane->name);
    }

    public function testCreateOrFirstWhenRecordDoesntExist(): void
    {
        $team = Team::create();
        $tony = $team->members()->create(['name' => 'Tony']);

        $article = $team->articles()->createOrFirst(
            ['title' => 'Laravel Forever'],
            ['user_id' => $tony->id],
        );

        $this->assertTrue($article->wasRecentlyCreated);
        $this->assertEquals('Laravel Forever', $article->title);
        $this->assertTrue($tony->is($article->user));
    }

    public function testCreateOrFirstWhenRecordExists(): void
    {
        $team = Team::create();
        $taylor = $team->members()->create(['name' => 'Taylor']);
        $tony = $team->members()->create(['name' => 'Tony']);

        $existingArticle = $taylor->articles()->create([
            'title' => 'Laravel Forever',
        ]);

        $newArticle = $team->articles()->createOrFirst(
            ['title' => 'Laravel Forever'],
            ['user_id' => $tony->id],
        );

        $this->assertFalse($newArticle->wasRecentlyCreated);
        $this->assertEquals('Laravel Forever', $newArticle->title);
        $this->assertTrue($taylor->is($newArticle->user));
        $this->assertTrue($existingArticle->is($newArticle));
    }

    public function testCreateOrFirstWhenRecordExistsInTransaction(): void
    {
        $team = Team::create();
        $taylor = $team->members()->create(['name' => 'Taylor']);
        $tony = $team->members()->create(['name' => 'Tony']);

        $existingArticle = $taylor->articles()->create([
            'title' => 'Laravel Forever',
        ]);

        $newArticle = DB::transaction(fn () => $team->articles()->createOrFirst(
            ['title' => 'Laravel Forever'],
            ['user_id' => $tony->id],
        ));

        $this->assertFalse($newArticle->wasRecentlyCreated);
        $this->assertEquals('Laravel Forever', $newArticle->title);
        $this->assertTrue($taylor->is($newArticle->user));
        $this->assertTrue($existingArticle->is($newArticle));
    }

    public function testCreateOrFirstRegressionIssue(): void
    {
        $team1 = Team::create();

        $taylor = $team1->members()->create(['name' => 'Taylor']);
        $tony = $team1->members()->create(['name' => 'Tony']);

        $existingTonyArticle = $tony->articles()->create(['title' => 'The New createOrFirst Method']);
        $existingTaylorArticle = $taylor->articles()->create(['title' => 'Laravel Forever']);

        $newArticle = $team1->articles()->createOrFirst(
            ['title' => 'Laravel Forever'],
            ['user_id' => $tony->id],
        );

        $this->assertFalse($newArticle->wasRecentlyCreated);
        $this->assertTrue($existingTaylorArticle->is($newArticle));
        $this->assertEquals('Laravel Forever', $newArticle->refresh()->title);
        $this->assertTrue($taylor->is($newArticle->user));

        $this->assertSame('Laravel Forever', $existingTaylorArticle->refresh()->title);
        $this->assertSame('The New createOrFirst Method', $existingTonyArticle->refresh()->title);
        $this->assertTrue($tony->is($existingTonyArticle->user));
    }

    public function testUpdateOrCreateAffectingWrongModelsRegression(): void
    {
        // On Laravel 10.21.0, a bug was introduced that would update the wrong model when using `updateOrCreate()`,
        // because the UPDATE statement would target a model based on the ID from the parent instead of the actual
        // conditions that the `updateOrCreate()` targeted. This test replicates the case that causes this bug.

        $team1 = Team::create();
        $team2 = Team::create();

        // Jane's ID should be the same as the $team1's ID for the bug to occur.
        $jane = User::create(['name' => 'Jane', 'slug' => 'jane-slug', 'team_id' => $team2->id]);
        $john = User::create(['name' => 'John', 'slug' => 'john-slug', 'team_id' => $team1->id]);

        $taylor = User::create(['name' => 'Taylor']);
        $team1->update(['owner_id' => $taylor->id]);

        $this->assertSame(2, $john->id);
        $this->assertSame(1, $jane->id);

        $this->assertSame(2, $john->refresh()->id);
        $this->assertSame(1, $jane->refresh()->id);

        $this->assertSame('john-slug', $john->slug);
        $this->assertSame('jane-slug', $jane->slug);

        $this->assertSame('john-slug', $john->refresh()->slug);
        $this->assertSame('jane-slug', $jane->refresh()->slug);

        // The `updateOrCreate` method would first try to find a matching attached record with a query like:
        // `->where($attributes)->first()`, which should return `John` of ID 2 in our case. However, it'd
        // return the incorrect ID of 1, which caused it to update Jane's record instead of John's.

        $taylor->teamMates()->updateOrCreate([
            'name' => 'John',
        ], [
            'slug' => 'john-doe',
        ]);

        // Expect $john's slug to be updated to john-doe instead of john-slug.
        $this->assertSame('john-doe', $john->fresh()->slug);
        // $jane should not be updated, because it belongs to a different user altogether.
        $this->assertSame('jane-slug', $jane->fresh()->slug);
    }

    public function testCanReplicateModelLoadedThroughHasManyThrough(): void
    {
        $team = Team::create();
        $user = User::create(['team_id' => $team->id, 'name' => 'John']);
        Article::create(['user_id' => $user->id, 'title' => 'John\'s new has-many-through-article']);

        $article = $team->articles()->first();

        $this->assertInstanceOf(Article::class, $article);

        $newArticle = $article->replicate();
        $newArticle->title .= ' v2';
        $newArticle->save();
    }

    public function testOne(): void
    {
        $team = Team::create();

        $user = User::create(['team_id' => $team->id, 'name' => Str::random()]);

        Article::create(['user_id' => $user->id, 'title' => Str::random(), 'created_at' => now()->subDay()]);
        $latestArticle = Article::create(['user_id' => $user->id, 'title' => Str::random(), 'created_at' => now()]);

        $this->assertEquals($latestArticle->id, $team->latestArticle->id);
    }
}

class User extends Model
{
    protected ?string $table = 'users';

    public bool $timestamps = false;

    protected array $guarded = [];

    /**
     * Get the members of the user's teams.
     */
    public function teamMates(): HasManyThrough
    {
        return $this->hasManyThrough(self::class, Team::class, 'owner_id', 'team_id');
    }

    /**
     * Get team members using the fluent through relationship.
     */
    public function teamMatesWithPendingRelation(): HasManyThrough
    {
        return $this->through($this->ownedTeams())
            ->has(fn (Team $team) => $team->members());
    }

    /**
     * Get team members using the owner's slug.
     */
    public function teamMatesBySlug(): HasManyThrough
    {
        return $this->hasManyThrough(self::class, Team::class, 'owner_slug', 'team_id', 'slug');
    }

    /**
     * Get team members through the owner's slug using a fluent relationship.
     */
    public function teamMatesBySlugWithPendingRelationship(): HasManyThrough
    {
        return $this->through($this->hasMany(Team::class, 'owner_slug', 'slug'))
            ->has(fn ($team) => $team->hasMany(User::class, 'team_id'));
    }

    /**
     * Get team members with the global column scope.
     */
    public function teamMatesWithGlobalScope(): HasManyThrough
    {
        return $this->hasManyThrough(UserWithGlobalScope::class, Team::class, 'owner_id', 'team_id');
    }

    /**
     * Get scoped team members using the fluent through relationship.
     */
    public function teamMatesWithGlobalScopeWithPendingRelation(): HasManyThrough
    {
        return $this->through($this->ownedTeams())
            ->has(fn (Team $team) => $team->membersWithGlobalScope());
    }

    /**
     * Get the teams owned by the user.
     */
    public function ownedTeams(): HasMany
    {
        return $this->hasMany(Team::class, 'owner_id');
    }

    /**
     * Get the user's team.
     */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /**
     * Get the user's articles.
     */
    public function articles(): HasMany
    {
        return $this->hasMany(Article::class);
    }
}

class UserWithGlobalScope extends Model
{
    protected ?string $table = 'users';

    public bool $timestamps = false;

    protected array $guarded = [];

    /**
     * Boot the model's global column scope.
     */
    public static function boot(): void
    {
        parent::boot();

        static::addGlobalScope(function ($query) {
            $query->select('users.id');
        });
    }
}

class Team extends Model
{
    protected ?string $table = 'teams';

    public bool $timestamps = false;

    protected array $guarded = [];

    /**
     * Get the team's members.
     */
    public function members(): HasMany
    {
        return $this->hasMany(User::class, 'team_id');
    }

    /**
     * Get team members with the global column scope.
     */
    public function membersWithGlobalScope(): HasMany
    {
        return $this->hasMany(UserWithGlobalScope::class, 'team_id');
    }

    /**
     * Get articles written by the team's members.
     */
    public function articles(): HasManyThrough
    {
        return $this->hasManyThrough(Article::class, User::class);
    }

    /**
     * Get the team's latest article.
     */
    public function latestArticle(): HasOneThrough
    {
        return $this->articles()->one()->latest();
    }
}

class Category extends Model
{
    use SoftDeletes;

    public bool $timestamps = false;

    protected array $guarded = [];

    /**
     * Get products in the category's child categories.
     */
    public function subProducts(): HasManyThrough
    {
        return $this->hasManyThrough(Product::class, self::class, 'parent_id');
    }
}

class Product extends Model
{
    public bool $timestamps = false;

    protected array $guarded = [];
}

class Article extends Model
{
    protected array $guarded = [];

    /**
     * Get the article's author.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
