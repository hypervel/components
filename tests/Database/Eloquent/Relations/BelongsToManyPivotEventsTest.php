<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Eloquent\Relations;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Support\ClassInvoker;
use Hypervel\Support\Facades\DB;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Tests that pivot model events fire when using a custom pivot class via ->using().
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

    public function testStockDetachKeepsPivotOrPredicatesInsideTheParentIdentity(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $otherUser = PivotEventsTestUser::forceCreate(['name' => 'Other User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);

        $user->rolesWithoutPivot()->attach($role->id, ['is_active' => true]);
        $otherUser->rolesWithoutPivot()->attach($role->id, ['is_active' => false]);

        $deleted = $user->rolesWithBooleanScope()->detach($role->id);

        $this->assertSame(1, $deleted);
        $this->assertDatabaseMissing('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $otherUser->id,
            'role_id' => $role->id,
        ]);
    }

    public function testCustomDetachKeepsPivotOrPredicatesInsideTheParentIdentity(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $otherUser = PivotEventsTestUser::forceCreate(['name' => 'Other User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);

        $user->rolesWithoutPivot()->attach($role->id, ['is_active' => true]);
        $otherUser->rolesWithoutPivot()->attach($role->id, ['is_active' => false]);

        $deleted = $user->rolesWithCustomBooleanScope()->detach($role->id);

        $this->assertSame(1, $deleted);
        $this->assertSame(['deleting', 'deleted'], PivotEventsTestCollaborator::$eventsCalled);
        $this->assertDatabaseMissing('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
        ]);
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $otherUser->id,
            'role_id' => $role->id,
        ]);
    }

    #[DataProvider('pivotRangeConstraintProvider')]
    public function testPivotRangeConstraintsApplyToDestructiveQueries(
        string $method,
        int $deletedPriority,
        int $retainedPriority,
    ): void {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $deletedRole = PivotEventsTestRole::forceCreate(['name' => 'Deleted']);
        $retainedRole = PivotEventsTestRole::forceCreate(['name' => 'Retained']);

        $user->rolesWithoutPivot()->attach($deletedRole->id, ['priority' => $deletedPriority]);
        $user->rolesWithoutPivot()->attach($retainedRole->id, ['priority' => $retainedPriority]);

        $relation = $user->rolesWithoutPivot();
        $relation->{$method}('priority', [1, 10]);

        $this->assertSame(1, $relation->detach());
        $this->assertDatabaseMissing('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $deletedRole->id,
        ]);
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $retainedRole->id,
        ]);
    }

    public static function pivotRangeConstraintProvider(): array
    {
        return [
            'where between' => ['wherePivotBetween', 5, 20],
            'or where between' => ['orWherePivotBetween', 5, 20],
            'where not between' => ['wherePivotNotBetween', 20, 5],
            'or where not between' => ['orWherePivotNotBetween', 20, 5],
        ];
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

    public function testCustomPivotWritesBypassMassAssignmentFilteringInStrictMode(): void
    {
        Model::shouldBeStrict();

        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);

        $user->rolesWithPivot()->attach($role->id, ['is_active' => false]);

        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_active' => false,
        ]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        $this->assertSame(1, $user->rolesWithPivot()->updateExistingPivot(
            $role->id,
            ['is_active' => true],
        ));
        $this->assertSame(
            ['saving', 'updating', 'updated', 'saved'],
            PivotEventsTestCollaborator::$eventsCalled,
        );
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'is_active' => true,
        ]);
    }

    public function testHydratedStockPivotSaveAndDeleteRetainRelationConstraints(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);

        $user->rolesInScopeOne()->attach($role->id, ['is_active' => true]);
        DB::table('pivot_events_role_user')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 2,
            'is_active' => true,
        ]);

        $pivot = $user->rolesInScopeOne()->firstOrFail()->pivot;
        $pivot->is_active = false;

        $this->assertTrue($pivot->save());
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 1,
            'is_active' => false,
        ]);
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 2,
            'is_active' => true,
        ]);

        $this->assertSame(1, $pivot->delete());
        $this->assertDatabaseMissing('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 1,
        ]);
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 2,
        ]);
    }

    public function testCustomPivotUpdateAndDetachRetainRelationConstraints(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);

        $user->rolesWithScopedPivot()->attach($role->id, ['is_active' => false]);
        DB::table('pivot_events_role_user')->insert([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 2,
            'is_active' => false,
        ]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        $this->assertSame(1, $user->rolesWithScopedPivot()->updateExistingPivot(
            $role->id,
            ['is_active' => true],
        ));
        $this->assertSame(
            ['saving', 'updating', 'updated', 'saved'],
            PivotEventsTestCollaborator::$eventsCalled,
        );
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 1,
            'is_active' => true,
        ]);
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 2,
            'is_active' => false,
        ]);

        PivotEventsTestCollaborator::$eventsCalled = [];

        $this->assertSame(1, $user->rolesWithScopedPivot()->detach($role->id));
        $this->assertSame(['deleting', 'deleted'], PivotEventsTestCollaborator::$eventsCalled);
        $this->assertDatabaseMissing('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 1,
        ]);
        $this->assertDatabaseHas('pivot_events_role_user', [
            'user_id' => $user->id,
            'role_id' => $role->id,
            'scope_id' => 2,
        ]);
    }

    public function testPrimaryKeyPivotKeepsNativeIdentityWhenAConstraintColumnChanges(): void
    {
        $user = PivotEventsTestUser::forceCreate(['name' => 'Test User']);
        $role = PivotEventsTestRole::forceCreate(['name' => 'Admin']);

        $user->rolesWithKeyedPivot()->attach($role->id, ['is_active' => true]);

        $pivot = $user->rolesWithKeyedPivot()->firstOrFail()->pivot;
        $query = $pivot->newQueryWithoutRelationships();
        (new ClassInvoker($pivot))->setKeysForSaveQuery($query);

        $this->assertSame('select * from "pivot_events_role_user_ids" where "id" = ?', $query->toSql());

        $pivot->scope_id = 2;

        $this->assertTrue($pivot->save());
        $this->assertDatabaseHas('pivot_events_role_user_ids', [
            'id' => $pivot->id,
            'scope_id' => 2,
        ]);
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

    /**
     * @return BelongsToMany<PivotEventsTestRole, $this>
     */
    public function rolesWithBooleanScope(): BelongsToMany
    {
        return $this->rolesWithoutPivot()
            ->wherePivot('is_active', true)
            ->orWherePivot('is_active', false);
    }

    /**
     * @return BelongsToMany<PivotEventsTestRole, $this, PivotEventsTestCollaborator>
     */
    public function rolesWithCustomBooleanScope(): BelongsToMany
    {
        return $this->rolesWithBooleanScope()->using(PivotEventsTestCollaborator::class);
    }

    /**
     * @return BelongsToMany<PivotEventsTestRole, $this>
     */
    public function rolesInScopeOne(): BelongsToMany
    {
        return $this->rolesWithoutPivot()
            ->withPivot('scope_id')
            ->withPivotValue('scope_id', 1);
    }

    /**
     * @return BelongsToMany<PivotEventsTestRole, $this, PivotEventsTestCollaborator>
     */
    public function rolesWithScopedPivot(): BelongsToMany
    {
        return $this->rolesInScopeOne()->using(PivotEventsTestCollaborator::class);
    }

    /**
     * @return BelongsToMany<PivotEventsTestRole, $this, PivotEventsKeyedTestCollaborator>
     */
    public function rolesWithKeyedPivot(): BelongsToMany
    {
        return $this->belongsToMany(
            PivotEventsTestRole::class,
            'pivot_events_role_user_ids',
            'user_id',
            'role_id',
        )->using(PivotEventsKeyedTestCollaborator::class)
            ->withPivot(['id', 'scope_id', 'is_active'])
            ->withPivotValue('scope_id', 1)
            ->withTimestamps();
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

    protected array $guarded = ['is_active'];

    public static array $eventsCalled = [];

    protected static function boot(): void
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

class PivotEventsKeyedTestCollaborator extends Pivot
{
    protected ?string $table = 'pivot_events_role_user_ids';

    public bool $incrementing = true;

    public bool $timestamps = true;
}
