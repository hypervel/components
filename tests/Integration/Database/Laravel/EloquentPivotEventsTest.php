<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentPivotEventsTest;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsTo;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Database\Eloquent\Relations\MorphTo;
use Hypervel\Database\Eloquent\Relations\MorphToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Hypervel\Tests\Integration\Database\DatabaseTestCase;

/**
 * @internal
 * @coversNothing
 */
class EloquentPivotEventsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // clear event log between requests
        PivotEventsTestCollaborator::$eventsCalled = [];
    }

    protected function tearDown(): void
    {
        // Clean up $_SERVER values used for test communication to prevent
        // pollution of other tests (especially those using remote() which
        // passes environment variables to subprocesses)
        unset($_SERVER['pivot_attributes'], $_SERVER['pivot_dirty_attributes']);

        parent::tearDown();
    }

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

        Schema::create('equipments', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('equipmentables', function (Blueprint $table) {
            $table->increments('id');
            $table->morphs('equipmentable');
            $table->foreignId('equipment_id');
        });

        Schema::create('project_users', function (Blueprint $table) {
            $table->integer('user_id');
            $table->integer('project_id');
            $table->text('permissions')->nullable();
            $table->string('role')->nullable();
        });
    }

    public function testPivotWillTriggerEventsToBeFired()
    {
        $user = PivotEventsTestUser::forceCreate(['email' => 'taylor@laravel.com']);
        $user2 = PivotEventsTestUser::forceCreate(['email' => 'ralph@ralphschindler.com']);
        $project = PivotEventsTestProject::forceCreate(['name' => 'Test Project']);

        $project->collaborators()->attach($user);
        $this->assertEquals(['saving', 'creating', 'created', 'saved'], PivotEventsTestCollaborator::$eventsCalled);

        PivotEventsTestCollaborator::$eventsCalled = [];
        $project->collaborators()->sync([$user2->id]);
        $this->assertEquals(['deleting', 'deleted', 'saving', 'creating', 'created', 'saved'], PivotEventsTestCollaborator::$eventsCalled);

        PivotEventsTestCollaborator::$eventsCalled = [];
        $project->collaborators()->sync([$user->id => ['role' => 'owner'], $user2->id => ['role' => 'contributor']]);
        $this->assertEquals(['saving', 'creating', 'created', 'saved', 'saving', 'updating', 'updated', 'saved'], PivotEventsTestCollaborator::$eventsCalled);

        PivotEventsTestCollaborator::$eventsCalled = [];
        $project->collaborators()->detach($user);
        $this->assertEquals(['deleting', 'deleted'], PivotEventsTestCollaborator::$eventsCalled);
    }

    public function testPivotWithPivotValueWillTriggerEventsToBeFired()
    {
        $user = PivotEventsTestUser::forceCreate(['email' => 'taylor@laravel.com']);
        $user2 = PivotEventsTestUser::forceCreate(['email' => 'ralph@ralphschindler.com']);
        $project = PivotEventsTestProject::forceCreate(['name' => 'Test Project']);

        $project->managers()->attach($user);
        $this->assertEquals(['saving', 'creating', 'created', 'saved'], PivotEventsTestCollaborator::$eventsCalled);
        $project->managers()->attach($user2);

        PivotEventsTestCollaborator::$eventsCalled = [];
        $project->managers()->updateExistingPivot($user->id, ['permissions' => ['foo', 'bar']]);
        $this->assertEquals(['saving', 'updating', 'updated', 'saved'], PivotEventsTestCollaborator::$eventsCalled);
        $project->managers()->detach($user2);

        PivotEventsTestCollaborator::$eventsCalled = [];
        $project->managers()->sync([$user2->id]);
        $this->assertEquals(['deleting', 'deleted', 'saving', 'creating', 'created', 'saved'], PivotEventsTestCollaborator::$eventsCalled);

        PivotEventsTestCollaborator::$eventsCalled = [];
        $project->managers()->sync([$user->id => ['permissions' => ['foo']], $user2->id => ['permissions' => ['bar']]]);
        $this->assertEquals(['saving', 'creating', 'created', 'saved', 'saving', 'updating', 'updated', 'saved'], PivotEventsTestCollaborator::$eventsCalled);

        PivotEventsTestCollaborator::$eventsCalled = [];
        $project->managers()->detach($user);
        $this->assertEquals(['deleting', 'deleted'], PivotEventsTestCollaborator::$eventsCalled);
    }

    public function testPivotWithPivotCriteriaTriggerEventsToBeFiredOnCreateUpdateNoneOnDetach()
    {
        $user = PivotEventsTestUser::forceCreate(['email' => 'taylor@laravel.com']);
        $user2 = PivotEventsTestUser::forceCreate(['email' => 'ralph@ralphschindler.com']);
        $project = PivotEventsTestProject::forceCreate(['name' => 'Test Project']);

        $project->contributors()->sync([$user->id, $user2->id]);
        $this->assertEquals(['saving', 'creating', 'created', 'saved', 'saving', 'creating', 'created', 'saved'], PivotEventsTestCollaborator::$eventsCalled);

        PivotEventsTestCollaborator::$eventsCalled = [];
        $project->contributors()->detach($user->id);
        $this->assertEquals([], PivotEventsTestCollaborator::$eventsCalled);
    }

    public function testCustomPivotUpdateEventHasExistingAttributes()
    {
        $_SERVER['pivot_attributes'] = false;

        $user = PivotEventsTestUser::forceCreate([
            'email' => 'taylor@laravel.com',
        ]);

        $project = PivotEventsTestProject::forceCreate([
            'name' => 'Test Project',
        ]);

        $project->collaborators()->attach($user, ['permissions' => ['foo', 'bar']]);

        $project->collaborators()->updateExistingPivot($user->id, ['role' => 'Lead Developer']);

        $this->assertEquals(
            [
                'user_id' => '1',
                'project_id' => '1',
                'permissions' => '["foo","bar"]',
                'role' => 'Lead Developer',
            ],
            $_SERVER['pivot_attributes']
        );
    }

    public function testCustomPivotUpdateEventHasDirtyCorrect()
    {
        $_SERVER['pivot_dirty_attributes'] = false;

        $user = PivotEventsTestUser::forceCreate([
            'email' => 'taylor@laravel.com',
        ]);

        $project = PivotEventsTestProject::forceCreate([
            'name' => 'Test Project',
        ]);

        $project->collaborators()->attach($user, ['permissions' => ['foo', 'bar'], 'role' => 'Developer']);

        $project->collaborators()->updateExistingPivot($user->id, ['role' => 'Lead Developer']);

        $this->assertSame(['role' => 'Lead Developer'], $_SERVER['pivot_dirty_attributes']);
    }

    public function testCustomMorphPivotClassDetachAttributes()
    {
        $project = PivotEventsTestProject::forceCreate([
            'name' => 'Test Project',
        ]);

        PivotEventsTestModelEquipment::deleting(function ($model) use ($project) {
            $this->assertInstanceOf(PivotEventsTestProject::class, $model->equipmentable);
            $this->assertEquals($project->id, $model->equipmentable->id);
        });

        $equipment = PivotEventsTestEquipment::forceCreate([
            'name' => 'important-equipment',
        ]);

        $project->equipments()->save($equipment);
        $equipment->projects()->sync([]);

        $this->assertEquals(
            [PivotEventsTestProject::class, PivotEventsTestProject::class, PivotEventsTestProject::class, PivotEventsTestProject::class, PivotEventsTestProject::class, PivotEventsTestProject::class],
            PivotEventsTestModelEquipment::$eventsMorphClasses
        );

        $this->assertEquals(
            ['equipmentable_type', 'equipmentable_type', 'equipmentable_type', 'equipmentable_type', 'equipmentable_type', 'equipmentable_type'],
            PivotEventsTestModelEquipment::$eventsMorphTypes
        );
    }
}

class PivotEventsTestUser extends Model
{
    public ?string $table = 'users';
}

class PivotEventsTestEquipment extends Model
{
    public ?string $table = 'equipments';

    public function getForeignKey(): string
    {
        return 'equipment_id';
    }

    public function projects(): MorphToMany
    {
        return $this->morphedByMany(PivotEventsTestProject::class, 'equipmentable')->using(PivotEventsTestModelEquipment::class);
    }
}

class PivotEventsTestProject extends Model
{
    public ?string $table = 'projects';

    public function collaborators(): BelongsToMany
    {
        return $this->belongsToMany(
            PivotEventsTestUser::class,
            'project_users',
            'project_id',
            'user_id'
        )->using(PivotEventsTestCollaborator::class);
    }

    public function contributors(): BelongsToMany
    {
        return $this->belongsToMany(PivotEventsTestUser::class, 'project_users', 'project_id', 'user_id')
            ->using(PivotEventsTestCollaborator::class)
            ->wherePivot('role', 'contributor');
    }

    public function managers(): BelongsToMany
    {
        return $this->belongsToMany(PivotEventsTestUser::class, 'project_users', 'project_id', 'user_id')
            ->using(PivotEventsTestCollaborator::class)
            ->withPivotValue('role', 'manager');
    }

    public function equipments(): MorphToMany
    {
        return $this->morphToMany(PivotEventsTestEquipment::class, 'equipmentable')->using(PivotEventsTestModelEquipment::class);
    }
}

class PivotEventsTestModelEquipment extends MorphPivot
{
    public ?string $table = 'equipmentables';

    public static array $eventsMorphClasses = [];

    public static array $eventsMorphTypes = [];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            static::$eventsMorphClasses[] = $model->morphClass;
            static::$eventsMorphTypes[] = $model->morphType;
        });

        static::created(function ($model) {
            static::$eventsMorphClasses[] = $model->morphClass;
            static::$eventsMorphTypes[] = $model->morphType;
        });

        static::updating(function ($model) {
            static::$eventsMorphClasses[] = $model->morphClass;
            static::$eventsMorphTypes[] = $model->morphType;
        });

        static::updated(function ($model) {
            static::$eventsMorphClasses[] = $model->morphClass;
            static::$eventsMorphTypes[] = $model->morphType;
        });

        static::saving(function ($model) {
            static::$eventsMorphClasses[] = $model->morphClass;
            static::$eventsMorphTypes[] = $model->morphType;
        });

        static::saved(function ($model) {
            static::$eventsMorphClasses[] = $model->morphClass;
            static::$eventsMorphTypes[] = $model->morphType;
        });

        static::deleting(function ($model) {
            static::$eventsMorphClasses[] = $model->morphClass;
            static::$eventsMorphTypes[] = $model->morphType;
        });

        static::deleted(function ($model) {
            static::$eventsMorphClasses[] = $model->morphClass;
            static::$eventsMorphTypes[] = $model->morphType;
        });
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(PivotEventsTestEquipment::class);
    }

    public function equipmentable(): MorphTo
    {
        return $this->morphTo();
    }
}

class PivotEventsTestCollaborator extends Pivot
{
    public ?string $table = 'project_users';

    public bool $timestamps = false;

    protected array $casts = [
        'permissions' => 'json',
    ];

    public static array $eventsCalled = [];

    public static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            static::$eventsCalled[] = 'creating';
        });

        static::created(function ($model) {
            static::$eventsCalled[] = 'created';
        });

        static::updating(function ($model) {
            static::$eventsCalled[] = 'updating';
        });

        static::updated(function ($model) {
            $_SERVER['pivot_attributes'] = $model->getAttributes();
            $_SERVER['pivot_dirty_attributes'] = $model->getDirty();
            static::$eventsCalled[] = 'updated';
        });

        static::saving(function ($model) {
            static::$eventsCalled[] = 'saving';
        });

        static::saved(function ($model) {
            static::$eventsCalled[] = 'saved';
        });

        static::deleting(function ($model) {
            static::$eventsCalled[] = 'deleting';
        });

        static::deleted(function ($model) {
            static::$eventsCalled[] = 'deleted';
        });
    }
}
