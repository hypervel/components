<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\MorphPivot;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\Traits\HasRoles;
use Hypervel\Support\Facades\DB;
use Hypervel\Tests\Permission\Fixtures\Models\UserWithoutHasRoles;

class CustomPivotTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        CustomPermissionPivotTestPermissionPivot::$events = [];
        CustomPermissionPivotTestRolePivot::$events = [];
    }

    public function testImmediatePermissionWritesUseThePublicCustomPivot(): void
    {
        $user = CustomPermissionPivotTestUser::create(['email' => 'custom@example.com']);

        $user->givePermissionTo('edit-articles');

        $this->assertSame(['created'], CustomPermissionPivotTestPermissionPivot::$events);
        $this->assertDatabaseHas('model_has_permissions', [
            'model_test_id' => $user->getKey(),
            'permission_test_id' => $this->testUserPermission->getKey(),
            'is_denied' => false,
        ]);

        CustomPermissionPivotTestPermissionPivot::$events = [];

        $user->denyPermissionTo('edit-articles');

        $this->assertSame(['updated'], CustomPermissionPivotTestPermissionPivot::$events);
        $this->assertDatabaseHas('model_has_permissions', [
            'model_test_id' => $user->getKey(),
            'permission_test_id' => $this->testUserPermission->getKey(),
            'is_denied' => true,
        ]);
    }

    public function testImmediateRoleWritesUseThePublicCustomPivot(): void
    {
        $user = CustomPermissionPivotTestUser::create(['email' => 'custom@example.com']);

        $user->assignRole('testRole');

        $this->assertSame(['created'], CustomPermissionPivotTestRolePivot::$events);

        CustomPermissionPivotTestRolePivot::$events = [];

        $user->syncRoles('testRole2');

        $this->assertSame(['deleted', 'created'], CustomPermissionPivotTestRolePivot::$events);
        $this->assertFalse($user->hasRole('testRole'));
        $this->assertTrue($user->hasRole('testRole2'));
    }

    public function testRoleSyncPreservesRetainedCustomPivotRows(): void
    {
        $user = CustomPermissionPivotTestUser::create(['email' => 'custom@example.com']);
        $user->assignRole('testRole');
        CustomPermissionPivotTestRolePivot::$events = [];

        $user->syncRoles('testRole');

        $this->assertSame([], CustomPermissionPivotTestRolePivot::$events);

        $user->syncRoles('testRole', 'testRole2');

        $this->assertSame(['created'], CustomPermissionPivotTestRolePivot::$events);
        $this->assertTrue($user->hasRole('testRole'));
        $this->assertTrue($user->hasRole('testRole2'));
    }

    public function testModelReturningPermissionApisReuseTheLoadedCustomPivotRelation(): void
    {
        $user = CustomPermissionPivotTestUser::create(['email' => 'custom@example.com']);
        $user->givePermissionTo('edit-articles');

        DB::enableQueryLog();
        DB::flushQueryLog();

        $directPermissions = $user->getDirectPermissions();
        $queriesAfterFirstLoad = count(DB::getQueryLog());

        $this->assertInstanceOf(
            CustomPermissionPivotTestPermissionPivot::class,
            $directPermissions->firstOrFail()->getRelation('pivot'),
        );
        $this->assertSame(['edit-articles'], $user->getDirectPermissions()->pluck('name')->all());
        $this->assertSame($queriesAfterFirstLoad, count(DB::getQueryLog()));

        $this->assertSame(
            CustomPermissionPivotTestPermissionPivot::class,
            $user->getAllPermissions()->firstOrFail()->getRelation('pivot')::class,
        );
        $queriesAfterAllPermissions = count(DB::getQueryLog());
        $this->assertSame(['edit-articles'], $user->getAllPermissions()->pluck('name')->all());
        $this->assertSame($queriesAfterAllPermissions, count(DB::getQueryLog()));

        $this->assertTrue($user->hasDirectPermission('edit-articles'));
        $this->assertSame(['edit-articles'], $user->getPermissionNames()->all());
        $this->assertSame($queriesAfterAllPermissions, count(DB::getQueryLog()));
    }

    public function testDeferredAssignmentsRetainTheirCustomPivotClasses(): void
    {
        $user = new CustomPermissionPivotTestUser(['email' => 'deferred@example.com']);

        $user->syncPermissionEffects(allowed: ['edit-articles'], denied: ['edit-news']);
        $user->syncRoles('testRole');
        $user->save();

        $this->assertSame(['created', 'created'], CustomPermissionPivotTestPermissionPivot::$events);
        $this->assertSame(['created'], CustomPermissionPivotTestRolePivot::$events);
        $this->assertTrue($user->hasDirectPermission('edit-articles'));
        $this->assertTrue($user->hasDeniedPermission('edit-news'));
        $this->assertTrue($user->hasRole('testRole'));
    }
}

class CustomPermissionPivotTestUser extends UserWithoutHasRoles
{
    use HasRoles {
        permissions as protected traitPermissions;
        roles as protected traitRoles;
    }

    protected string $guard_name = 'web';

    /**
     * @return BelongsToMany<Permission, $this, CustomPermissionPivotTestPermissionPivot>
     */
    public function permissions(): BelongsToMany
    {
        return $this->traitPermissions()->using(CustomPermissionPivotTestPermissionPivot::class);
    }

    /**
     * @return BelongsToMany<Role, $this, CustomPermissionPivotTestRolePivot>
     */
    public function roles(): BelongsToMany
    {
        return $this->traitRoles()->using(CustomPermissionPivotTestRolePivot::class);
    }
}

class CustomPermissionPivotTestPermissionPivot extends MorphPivot
{
    protected array $casts = [
        'is_denied' => 'boolean',
    ];

    protected array $guarded = ['is_denied'];

    public static array $events = [];

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (): void {
            static::$events[] = 'created';
        });
        static::updated(function (): void {
            static::$events[] = 'updated';
        });
        static::deleted(function (): void {
            static::$events[] = 'deleted';
        });
    }
}

class CustomPermissionPivotTestRolePivot extends MorphPivot
{
    public static array $events = [];

    protected static function boot(): void
    {
        parent::boot();

        static::created(function (): void {
            static::$events[] = 'created';
        });
        static::updated(function (): void {
            static::$events[] = 'updated';
        });
        static::deleted(function (): void {
            static::$events[] = 'deleted';
        });
    }
}
