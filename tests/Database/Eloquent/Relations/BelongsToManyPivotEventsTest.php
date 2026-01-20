<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Testbench\TestCase;

/**
 * Tests that pivot model events fire when using a custom pivot class via ->using().
 *
 * @internal
 * @coversNothing
 */
class BelongsToManyPivotEventsTest extends TestCase
{
    use RefreshDatabase;

    protected bool $migrateRefresh = true;

    protected function migrateFreshUsing(): array
    {
        return [
            '--database' => $this->getRefreshConnection(),
            '--realpath' => true,
            '--path' => __DIR__ . '/migrations',
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Clear event log between tests
        PivotEventsTestCollaborator::$eventsCalled = [];
    }

    // =========================================================================
    // Tests for attach()
    // =========================================================================

    public function testAttachFiresCreatingAndCreatedEventsWithCustomPivot(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);

        $user->rolesWithPivot()->attach($role);

        $this->assertEquals(
            ['saving', 'creating', 'created', 'saved'],
            PivotEventsTestCollaborator::$eventsCalled
        );
    }

    public function testAttachMultipleFiresEventsForEachRecord(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role1 = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $role2 = PivotEventsTestRole::forceCreate(['name' => 'Editor']);
        $role3 = PivotEventsTestRole::forceCreate(['name' => 'Viewer']);

        $user->rolesWithPivot()->attach([$role1->id, $role2->id, $role3->id]);

        // 3 creates = 3x (saving, creating, created, saved)
        $this->assertCount(12, PivotEventsTestCollaborator::$eventsCalled);
        $this->assertEquals(3, substr_count(implode(',', PivotEventsTestCollaborator::$eventsCalled), 'creating'));
        $this->assertEquals(3, substr_count(implode(',', PivotEventsTestCollaborator::$eventsCalled), 'created'));
    }

    public function testAttachWithoutCustomPivotDoesNotFireEvents(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);

        // Using rolesWithoutPivot which doesn't use ->using()
        $user->rolesWithoutPivot()->attach($role->id);

        $this->assertEquals([], PivotEventsTestCollaborator::$eventsCalled);

        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
    }

    // =========================================================================
    // Tests for detach()
    // =========================================================================

    public function testDetachFiresDeletingAndDeletedEventsWithCustomPivot(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $user->rolesWithPivot()->attach($role->id);

        PivotEventsTestCollaborator::$eventsCalled = [];

        $deleted = $user->rolesWithPivot()->detach($role->id);

        $this->assertSame(1, $deleted);
        $this->assertEquals(['deleting', 'deleted'], PivotEventsTestCollaborator::$eventsCalled);
    }

    public function testDetachMultipleFiresEventsForEachRecord(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role1 = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $role2 = PivotEventsTestRole::forceCreate(['name' => 'Editor']);
        $user->rolesWithPivot()->attach([$role1->id, $role2->id]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        $deleted = $user->rolesWithPivot()->detach([$role1->id, $role2->id]);

        $this->assertSame(2, $deleted);
        $this->assertEquals(['deleting', 'deleted', 'deleting', 'deleted'], PivotEventsTestCollaborator::$eventsCalled);
    }

    public function testDetachAllFiresEventsForAllRecords(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role1 = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $role2 = PivotEventsTestRole::forceCreate(['name' => 'Editor']);
        $user->rolesWithPivot()->attach([$role1->id, $role2->id]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        $deleted = $user->rolesWithPivot()->detach();

        $this->assertSame(2, $deleted);
        $this->assertEquals(['deleting', 'deleted', 'deleting', 'deleted'], PivotEventsTestCollaborator::$eventsCalled);
    }

    public function testDetachWithoutCustomPivotDoesNotFireEvents(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $user->rolesWithoutPivot()->attach($role->id);

        PivotEventsTestCollaborator::$eventsCalled = [];

        $user->rolesWithoutPivot()->detach($role->id);

        $this->assertEquals([], PivotEventsTestCollaborator::$eventsCalled);
    }

    // =========================================================================
    // Tests for updateExistingPivot()
    // =========================================================================

    public function testUpdateExistingPivotFiresSavingAndSavedEventsWithCustomPivot(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $user->rolesWithPivot()->attach($role->id, ['is_active' => false]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        $updated = $user->rolesWithPivot()->updateExistingPivot($role->id, ['is_active' => true]);

        $this->assertSame(1, $updated);
        $this->assertEquals(['saving', 'updating', 'updated', 'saved'], PivotEventsTestCollaborator::$eventsCalled);
    }

    public function testUpdateExistingPivotDoesNotFireEventsWhenNotDirty(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $user->rolesWithPivot()->attach($role->id, ['is_active' => true]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        // Update with same value - should not be dirty
        $updated = $user->rolesWithPivot()->updateExistingPivot($role->id, ['is_active' => true]);

        $this->assertSame(0, $updated);
        $this->assertEquals([], PivotEventsTestCollaborator::$eventsCalled);
    }

    public function testUpdateExistingPivotWithoutCustomPivotDoesNotFireEvents(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $user->rolesWithoutPivot()->attach($role->id, ['is_active' => false]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        $user->rolesWithoutPivot()->updateExistingPivot($role->id, ['is_active' => true]);

        $this->assertEquals([], PivotEventsTestCollaborator::$eventsCalled);
    }

    // =========================================================================
    // Tests for sync()
    // =========================================================================

    public function testSyncFiresEventsForAttachAndDetach(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role1 = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $role2 = PivotEventsTestRole::forceCreate(['name' => 'Editor']);
        $role3 = PivotEventsTestRole::forceCreate(['name' => 'Viewer']);

        // Attach role1 and role2
        $user->rolesWithPivot()->attach([$role1->id, $role2->id]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        // Sync to role2 and role3 (detaches role1, attaches role3, keeps role2)
        $changes = $user->rolesWithPivot()->sync([$role2->id, $role3->id]);

        $this->assertSame([$role1->id], $changes['detached']);
        $this->assertSame([$role3->id], $changes['attached']);

        $this->assertEquals(
            ['deleting', 'deleted', 'saving', 'creating', 'created', 'saved'],
            PivotEventsTestCollaborator::$eventsCalled
        );
    }

    public function testSyncWithPivotValuesFiresEventsForUpdates(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $user->rolesWithPivot()->attach($role->id, ['is_active' => false]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        // Sync with updated pivot value
        $changes = $user->rolesWithPivot()->sync([
            $role->id => ['is_active' => true],
        ]);

        $this->assertSame([$role->id], $changes['updated']);
        $this->assertEquals(['saving', 'updating', 'updated', 'saved'], PivotEventsTestCollaborator::$eventsCalled);
    }

    // =========================================================================
    // Tests for toggle()
    // =========================================================================

    public function testToggleFiresEventsForAttachAndDetach(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role1 = PivotEventsTestRole::forceCreate(['name' => 'Admin']);
        $role2 = PivotEventsTestRole::forceCreate(['name' => 'Editor']);

        // Attach role1
        $user->rolesWithPivot()->attach($role1->id);

        PivotEventsTestCollaborator::$eventsCalled = [];

        // Toggle role1 (detach) and role2 (attach)
        $changes = $user->rolesWithPivot()->toggle([$role1->id, $role2->id]);

        $this->assertSame([$role1->id], $changes['detached']);
        $this->assertContains($role2->id, $changes['attached']);

        $this->assertEquals(
            ['deleting', 'deleted', 'saving', 'creating', 'created', 'saved'],
            PivotEventsTestCollaborator::$eventsCalled
        );
    }
}

// =============================================================================
// Test Models
// =============================================================================

class PivotEventsTestUser extends Model
{
    protected ?string $table = 'pivot_events_users';

    protected array $guarded = [];

    /**
     * Relationship WITH custom pivot class - should fire events.
     *
     * @return BelongsToMany<PivotEventsTestRole, $this, PivotEventsTestCollaborator>
     */
    public function rolesWithPivot(): BelongsToMany
    {
        return $this->belongsToMany(
            PivotEventsTestRole::class,
            'pivot_events_role_user',
            'user_id',
            'role_id'
        )->using(PivotEventsTestCollaborator::class)->withPivot('is_active')->withTimestamps();
    }

    /**
     * Relationship WITHOUT custom pivot class - should NOT fire events (uses raw queries).
     *
     * @return BelongsToMany<PivotEventsTestRole, $this>
     */
    public function rolesWithoutPivot(): BelongsToMany
    {
        return $this->belongsToMany(
            PivotEventsTestRole::class,
            'pivot_events_role_user',
            'user_id',
            'role_id'
        )->withPivot('is_active')->withTimestamps();
    }
}

class PivotEventsTestRole extends Model
{
    protected ?string $table = 'pivot_events_roles';

    protected array $guarded = [];
}

class PivotEventsTestCollaborator extends Pivot
{
    protected ?string $table = 'pivot_events_role_user';

    public bool $incrementing = false;

    public bool $timestamps = true;

    protected array $casts = [
        'is_active' => 'boolean',
    ];

    public static array $eventsCalled = [];

    protected function boot(): void
    {
        parent::boot();

        static::registerCallback('creating', function ($model) {
            static::$eventsCalled[] = 'creating';
        });

        static::registerCallback('created', function ($model) {
            static::$eventsCalled[] = 'created';
        });

        static::registerCallback('updating', function ($model) {
            static::$eventsCalled[] = 'updating';
        });

        static::registerCallback('updated', function ($model) {
            static::$eventsCalled[] = 'updated';
        });

        static::registerCallback('saving', function ($model) {
            static::$eventsCalled[] = 'saving';
        });

        static::registerCallback('saved', function ($model) {
            static::$eventsCalled[] = 'saved';
        });

        static::registerCallback('deleting', function ($model) {
            static::$eventsCalled[] = 'deleting';
        });

        static::registerCallback('deleted', function ($model) {
            static::$eventsCalled[] = 'deleted';
        });
    }
}
