<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\DatabaseEloquentBelongsToManyWherePivotClosureTest;

use Hypervel\Database\Capsule\Manager as DB;
use Hypervel\Database\ConnectionInterface;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model as Eloquent;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Database\Schema\Builder as SchemaBuilder;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\TestWith;

class DatabaseEloquentBelongsToManyWherePivotClosureTest extends TestCase
{
    protected DB $database;

    /**
     * Set up the database schema.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $db = $this->database = new DB;

        $db->addConnection([
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $db->bootEloquent();
        $db->setAsGlobal();

        $this->createSchema();
    }

    /**
     * Create the test tables.
     */
    protected function createSchema(): void
    {
        $this->schema()->create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });

        $this->schema()->create('projects', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
        });

        $this->schema()->create('project_user', function (Blueprint $table): void {
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member');
            $table->boolean('muted')->default(false);
        });
    }

    /**
     * Drop the test tables.
     */
    protected function tearDown(): void
    {
        $this->schema()->drop('project_user');
        $this->schema()->drop('projects');
        $this->schema()->drop('users');

        parent::tearDown();
    }

    public function testWherePivotWithClosureCallsPivotModelScope(): void
    {
        $project = WherePivotClosureProject::create(['title' => 'Project 1']);
        $active = WherePivotClosureUser::create(['name' => 'Active User']);
        $muted = WherePivotClosureUser::create(['name' => 'Muted User']);

        $project->subscribers()->attach($active->id, ['muted' => false, 'role' => 'admin']);
        $project->subscribers()->attach($muted->id, ['muted' => true, 'role' => 'member']);

        $results = $project->subscribers()->wherePivot(function (Builder $query): void {
            $query->active();
        })->get();

        $this->assertCount(1, $results);
        $this->assertEquals($active->id, $results->first()->id);
    }

    public function testOrWherePivotWithClosureCallsPivotModelScope(): void
    {
        $project = WherePivotClosureProject::create(['title' => 'Project 1']);
        $admin = WherePivotClosureUser::create(['name' => 'Admin']);
        $active = WherePivotClosureUser::create(['name' => 'Active']);
        $mutedMember = WherePivotClosureUser::create(['name' => 'Muted Member']);

        $project->subscribers()->attach($admin->id, ['muted' => true, 'role' => 'admin']);
        $project->subscribers()->attach($active->id, ['muted' => false, 'role' => 'member']);
        $project->subscribers()->attach($mutedMember->id, ['muted' => true, 'role' => 'member']);

        $results = $project->subscribers()
            ->wherePivot('role', 'admin')
            ->orWherePivot(function (Builder $query): void {
                $query->active();
            })
            ->get();

        $this->assertCount(2, $results);
        $this->assertTrue($results->contains('id', $admin->id));
        $this->assertTrue($results->contains('id', $active->id));
    }

    public function testWherePivotClosureWithMultipleScopeConditions(): void
    {
        $project = WherePivotClosureProject::create(['title' => 'Project 1']);
        $activeAdmin = WherePivotClosureUser::create(['name' => 'Active Admin']);
        $activeMember = WherePivotClosureUser::create(['name' => 'Active Member']);
        $mutedAdmin = WherePivotClosureUser::create(['name' => 'Muted Admin']);

        $project->subscribers()->attach($activeAdmin->id, ['muted' => false, 'role' => 'admin']);
        $project->subscribers()->attach($activeMember->id, ['muted' => false, 'role' => 'member']);
        $project->subscribers()->attach($mutedAdmin->id, ['muted' => true, 'role' => 'admin']);

        $results = $project->subscribers()->wherePivot(function (Builder $query): void {
            $query->active()->admins();
        })->get();

        $this->assertCount(1, $results);
        $this->assertEquals($activeAdmin->id, $results->first()->id);
    }

    public function testWherePivotClosureWithInlineWhere(): void
    {
        $project = WherePivotClosureProject::create(['title' => 'Project 1']);
        $user1 = WherePivotClosureUser::create(['name' => 'User 1']);
        $user2 = WherePivotClosureUser::create(['name' => 'User 2']);

        $project->subscribers()->attach($user1->id, ['muted' => false, 'role' => 'admin']);
        $project->subscribers()->attach($user2->id, ['muted' => false, 'role' => 'member']);

        $results = $project->subscribers()->wherePivot(function (Builder $query): void {
            $query->where('role', 'admin');
        })->get();

        $this->assertCount(1, $results);
        $this->assertEquals($user1->id, $results->first()->id);
    }

    public function testWherePivotWithClosureScopesDetach(): void
    {
        $project = WherePivotClosureProject::create(['title' => 'Project 1']);
        $active = WherePivotClosureUser::create(['name' => 'Active User']);
        $muted = WherePivotClosureUser::create(['name' => 'Muted User']);

        $project->subscribers()->attach($active->id, ['muted' => false]);
        $project->subscribers()->attach($muted->id, ['muted' => true]);

        $project->subscribers()->wherePivot(function (Builder $query): void {
            $query->active();
        })->detach();

        $this->assertCount(1, $project->subscribers()->get());
        $this->assertTrue($project->subscribers()->get()->contains('id', $muted->id));
    }

    public function testWherePivotWithClosureScopesSync(): void
    {
        $project = WherePivotClosureProject::create(['title' => 'Project 1']);
        $active = WherePivotClosureUser::create(['name' => 'Active User']);
        $muted = WherePivotClosureUser::create(['name' => 'Muted User']);

        $project->subscribers()->attach($active->id, ['muted' => false]);
        $project->subscribers()->attach($muted->id, ['muted' => true]);

        $project->subscribers()->wherePivot(function (Builder $query): void {
            $query->active();
        })->sync([]);

        $this->assertTrue($project->subscribers()->get()->contains('id', $muted->id));
        $this->assertFalse($project->subscribers()->get()->contains('id', $active->id));
    }

    public function testWherePivotWithClosureScopesUpdateExistingPivot(): void
    {
        $project = WherePivotClosureProject::create(['title' => 'Project 1']);
        $active = WherePivotClosureUser::create(['name' => 'Active User']);
        $muted = WherePivotClosureUser::create(['name' => 'Muted User']);

        $project->subscribers()->attach($active->id, ['muted' => false, 'role' => 'member']);
        $project->subscribers()->attach($muted->id, ['muted' => true, 'role' => 'member']);

        $affected = $project->subscribers()->wherePivot(function (Builder $query): void {
            $query->active();
        })->updateExistingPivot($muted->id, ['role' => 'admin']);

        $this->assertSame(0, $affected);
        $this->assertSame('member', $project->subscribers()->find($muted->id)->pivot->role);
    }

    public function testClosureUsesTheRelationsReadConnection(): void
    {
        $this->database->addConnection(['driver' => 'sqlite', 'database' => ':memory:'], 'tenant');
        $project = (new WherePivotClosureProject)->setConnection('tenant::read');
        $project->id = 1;
        $relation = $project->subscribers();
        $connection = $relation->getQuery()->getConnection();

        $this->assertNotSame($this->database->getConnection('tenant'), $connection);

        $relation->wherePivot(function (Builder $query) use ($connection): void {
            $this->assertSame($connection, $query->getConnection());
            $query->where('muted', false);
        });
    }

    public function testPivotClosureWorksWithEagerLoadingAndExistenceQueries(): void
    {
        $project = WherePivotClosureProject::create(['title' => 'Active project']);
        $mutedProject = WherePivotClosureProject::create(['title' => 'Muted project']);
        $user = WherePivotClosureUser::create(['name' => 'Subscriber']);
        $project->subscribers()->attach($user->id, ['muted' => false]);
        $mutedProject->subscribers()->attach($user->id, ['muted' => true]);

        $projects = WherePivotClosureProject::with('activeSubscribers')
            ->withCount('activeSubscribers')
            ->whereHas('activeSubscribers')
            ->get();

        $this->assertSame([$project->id], $projects->modelKeys());
        $this->assertSame([$user->id], $projects->first()->activeSubscribers->modelKeys());
        $this->assertSame(1, $projects->first()->active_subscribers_count);
    }

    #[TestWith(['wherePivot'])]
    #[TestWith(['wherePivotIn'])]
    public function testSubqueryIsEvaluatedOnceForReadsAndWrites(string $method): void
    {
        $project = WherePivotClosureProject::create(['title' => 'Project 1']);
        $admin = WherePivotClosureUser::create(['name' => 'Admin']);
        $member = WherePivotClosureUser::create(['name' => 'Member']);
        $project->subscribers()->attach($admin->id, ['role' => 'admin']);
        $project->subscribers()->attach($member->id, ['role' => 'member']);
        $calls = 0;

        $relation = $project->subscribers()->{$method}('role', function (QueryBuilder $query) use (&$calls): void {
            $query->selectRaw('?', [++$calls === 1 ? 'admin' : 'member']);
        });

        $this->assertSame([$admin->id], $relation->get()->modelKeys());
        $this->assertSame(1, $relation->detach());
        $this->assertSame(1, $calls);
        $this->assertSame([$member->id], $project->subscribers()->get()->modelKeys());
    }

    /**
     * Get the test connection.
     */
    protected function connection(): ConnectionInterface
    {
        return Eloquent::getConnectionResolver()->connection();
    }

    /**
     * Get the schema builder.
     */
    protected function schema(): SchemaBuilder
    {
        return $this->connection()->getSchemaBuilder();
    }
}

class WherePivotClosureProject extends Eloquent
{
    protected ?string $table = 'projects';

    protected array $guarded = [];

    public bool $timestamps = false;

    /**
     * Get the project subscribers.
     *
     * @return BelongsToMany<WherePivotClosureUser, $this, WherePivotClosureSubscription>
     */
    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(WherePivotClosureUser::class, 'project_user', 'project_id', 'user_id')
            ->using(WherePivotClosureSubscription::class)
            ->withPivot(['role', 'muted']);
    }

    /**
     * Get active project subscribers.
     *
     * @return BelongsToMany<WherePivotClosureUser, $this, WherePivotClosureSubscription>
     */
    public function activeSubscribers(): BelongsToMany
    {
        return $this->subscribers()->wherePivot(fn (Builder $query): Builder => $query->active());
    }
}

class WherePivotClosureUser extends Eloquent
{
    protected ?string $table = 'users';

    protected array $guarded = [];

    public bool $timestamps = false;
}

class WherePivotClosureSubscription extends Pivot
{
    protected ?string $table = 'project_user';

    /**
     * Restrict the query to active subscriptions.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('muted', false);
    }

    /**
     * Restrict the query to administrators.
     *
     * @param Builder<static> $query
     * @return Builder<static>
     */
    public function scopeAdmins(Builder $query): Builder
    {
        return $query->where('role', 'admin');
    }
}
