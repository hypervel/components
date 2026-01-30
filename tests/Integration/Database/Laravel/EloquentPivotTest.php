<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentPivotTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentPivotTest extends DatabaseTestCase
{
    protected function afterRefreshingDatabase(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('email');
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('collaborators', function (Blueprint $table) {
            $table->integer('user_id');
            $table->integer('project_id');
            $table->text('permissions')->nullable();
        });

        Schema::create('contributors', function (Blueprint $table) {
            $table->id();
            $table->integer('user_id');
            $table->integer('project_id');
            $table->text('permissions')->nullable();
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->integer('user_id');
            $table->integer('project_id');
            $table->string('status');
        });
    }

    public function testPivotConvenientHelperReturnExpectedResult()
    {
        $user = PivotTestUser::forceCreate(['email' => 'taylor@laravel.com']);
        $user2 = PivotTestUser::forceCreate(['email' => 'ralph@ralphschindler.com']);
        $project = PivotTestProject::forceCreate(['name' => 'Test Project']);

        $project->contributors()->attach($user);
        $project->collaborators()->attach($user2);

        tap($project->contributors->first()->pivot, function ($pivot) {
            $this->assertEquals(1, $pivot->getKey());
            $this->assertEquals(1, $pivot->getQueueableId());
            $this->assertSame('user_id', $pivot->getRelatedKey());
            $this->assertSame('project_id', $pivot->getForeignKey());
        });

        tap($project->collaborators->first()->pivot, function ($pivot) {
            $this->assertNull($pivot->getKey());
            $this->assertSame('project_id:1:user_id:2', $pivot->getQueueableId());
            $this->assertSame('user_id', $pivot->getRelatedKey());
            $this->assertSame('project_id', $pivot->getForeignKey());
        });
    }

    public function testPivotValuesCanBeSetFromRelationDefinition()
    {
        $user = PivotTestUser::forceCreate(['email' => 'taylor@laravel.com']);
        $active = PivotTestProject::forceCreate(['name' => 'Active Project']);
        $inactive = PivotTestProject::forceCreate(['name' => 'Inactive Project']);

        $this->assertSame('active', $user->activeSubscriptions()->newPivot()->status);
        $this->assertSame('inactive', $user->inactiveSubscriptions()->newPivot()->status);

        $user->activeSubscriptions()->attach($active);
        $user->inactiveSubscriptions()->attach($inactive);

        $this->assertSame('active', $user->activeSubscriptions->first()->pivot->status);
        $this->assertSame('inactive', $user->inactiveSubscriptions->first()->pivot->status);
    }
}

class PivotTestUser extends Model
{
    public ?string $table = 'users';

    public function activeSubscriptions(): BelongsToMany
    {
        return $this->belongsToMany(PivotTestProject::class, 'subscriptions', 'user_id', 'project_id')
            ->withPivotValue('status', 'active')
            ->withPivot('status')
            ->using(PivotTestSubscription::class);
    }

    public function inactiveSubscriptions(): BelongsToMany
    {
        return $this->belongsToMany(PivotTestProject::class, 'subscriptions', 'user_id', 'project_id')
            ->withPivotValue('status', 'inactive')
            ->withPivot('status')
            ->using(PivotTestSubscription::class);
    }
}

class PivotTestProject extends Model
{
    public ?string $table = 'projects';

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(
            PivotTestUser::class,
            'collaborators',
            'project_id',
            'user_id'
        )->withPivot('permissions')
            ->using(PivotTestCollaborator::class);
    }

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(PivotTestUser::class, 'contributors', 'project_id', 'user_id')
            ->withPivot('id', 'permissions')
            ->using(PivotTestContributor::class);
    }
}

class PivotTestCollaborator extends Pivot
{
    public ?string $table = 'collaborators';

    public bool $timestamps = false;

    protected array $casts = [
        'permissions' => 'json',
    ];
}

class PivotTestContributor extends Pivot
{
    public ?string $table = 'contributors';

    public bool $timestamps = false;

    public bool $incrementing = true;

    protected array $casts = [
        'permissions' => 'json',
    ];
}

class PivotTestSubscription extends Pivot
{
    public ?string $table = 'subscriptions';

    public bool $timestamps = false;

    protected array $attributes = [
        'status' => 'active',
    ];
}
