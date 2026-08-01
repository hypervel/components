<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Models\PermissionTest;

use BackedEnum;
use Hypervel\Database\Eloquent\MissingAttributeException;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Permission;
use Hypervel\Permission\Exceptions\PermissionAlreadyExists;
use Hypervel\Permission\Models\Permission as PermissionModel;
use Hypervel\Tests\Permission\Fixtures\Models\User;
use Hypervel\Tests\Permission\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

enum TestPermissionEnum: string
{
    case TestPermission = 'test-permission';
}

class PermissionTest extends TestCase
{
    public function testItGetsUserModelsUsingWith(): void
    {
        $this->testUser->givePermissionTo($this->testUserPermission);

        $permission = $this->app->make(Permission::class)::with('users')
            ->where($this->testUserPermission->getKeyName(), $this->testUserPermission->getKey())
            ->first();

        $this->assertSame($this->testUserPermission->getKey(), $permission->getKey());
        $this->assertCount(1, $permission->users);
        $this->assertSame($this->testUser->id, $permission->users[0]->id);
    }

    #[DataProvider('permissionNameProvider')]
    public function testItCanBeCreated(string|BackedEnum $name, string $expected): void
    {
        $permission = $this->app->make(Permission::class)->create(['name' => $name]);

        $this->assertSame($expected, $permission->name);
    }

    #[DataProvider('permissionNameProvider')]
    public function testItCanFindByName(string|BackedEnum $name, string $expected): void
    {
        $this->app->make(Permission::class)->create(['name' => $name]);

        $permission = $this->app->make(Permission::class)->findByName($name);

        $this->assertSame($expected, $permission->name);
    }

    #[DataProvider('permissionNameProvider')]
    public function testItCanFindOrCreateByName(string|BackedEnum $name, string $expected): void
    {
        $permission = $this->app->make(Permission::class)->findOrCreate($name);

        $this->assertSame($expected, $permission->name);
    }

    /**
     * Provide permission names.
     */
    public static function permissionNameProvider(): array
    {
        return [
            'string' => ['test-permission', 'test-permission'],
            'enum' => [TestPermissionEnum::TestPermission, TestPermissionEnum::TestPermission->value],
        ];
    }

    #[DataProvider('permissionNameOnlyProvider')]
    public function testItThrowsAnExceptionWhenThePermissionAlreadyExists(string|BackedEnum $name): void
    {
        $this->app->make(Permission::class)->create(['name' => $name]);

        $this->expectException(PermissionAlreadyExists::class);

        $this->app->make(Permission::class)->create(['name' => $name]);
    }

    /**
     * Provide permission names without expected values.
     */
    public static function permissionNameOnlyProvider(): array
    {
        return [
            'string' => ['test-permission'],
            'enum' => [TestPermissionEnum::TestPermission],
        ];
    }

    public function testItBelongsToAGuard(): void
    {
        $permission = $this->app->make(Permission::class)->create(['name' => 'can-edit', 'guard_name' => 'admin']);

        $this->assertSame('admin', $permission->guard_name);
    }

    public function testItBelongsToTheDefaultGuardByDefault(): void
    {
        $this->assertSame(
            $this->app->make('config')->get('auth.defaults.guard'),
            $this->testUserPermission->guard_name,
        );
    }

    public function testGuardNamePreservesDefaultsLoadedValuesAccessorsAndStringZero(): void
    {
        $this->assertSame(
            $this->app->make('config')->get('auth.defaults.guard'),
            (new PermissionModel)->guardName(),
        );
        $this->assertSame('admin', (new PermissionModel(['guard_name' => 'admin']))->guardName());
        $this->assertSame('accessed', (new PermissionWithGuardNameAccessor(['guard_name' => 'stored']))->guardName());
        $this->assertSame('0', (new PermissionModel(['guard_name' => '0']))->guardName());
    }

    public function testGuardNameRejectsPersistedPermissionsMissingTheGuardColumn(): void
    {
        Model::preventAccessingMissingAttributes(false);

        $permission = PermissionModel::query()
            ->select($this->testUserPermission->getKeyName(), 'name')
            ->findOrFail($this->testUserPermission->getKey());

        $this->expectException(MissingAttributeException::class);
        $this->expectExceptionMessage('The attribute [guard_name]');

        $permission->guardName();
    }

    public function testUsersRelationRejectsPermissionsMissingTheGuardColumn(): void
    {
        Model::preventAccessingMissingAttributes(false);

        $permission = PermissionModel::query()
            ->select($this->testUserPermission->getKeyName(), 'name')
            ->findOrFail($this->testUserPermission->getKey());

        $this->expectException(MissingAttributeException::class);
        $this->expectExceptionMessage('The attribute [guard_name]');

        $permission->users();
    }

    public function testItHasUserModelsOfTheRightClass(): void
    {
        $this->testAdmin->givePermissionTo($this->testAdminPermission);
        $this->testUser->givePermissionTo($this->testUserPermission);

        $this->assertCount(1, $this->testUserPermission->users);
        $this->assertTrue($this->testUserPermission->users->first()->is($this->testUser));
        $this->assertInstanceOf(User::class, $this->testUserPermission->users->first());
    }

    public function testItIsRetrievableById(): void
    {
        $permission = $this->app->make(Permission::class)->findById($this->testUserPermission->id);

        $this->assertSame($this->testUserPermission->id, $permission->id);
    }

    public function testItCanDeleteHydratedPermissions(): void
    {
        $this->reloadPermissions();

        $permission = $this->app->make(Permission::class)->findByName($this->testUserPermission->name);
        $permission->delete();

        $this->assertCount(0, $this->app->make(Permission::class)
            ->where($this->testUserPermission->getKeyName(), $this->testUserPermission->getKey())
            ->get());
    }

    public function testItDoesNotTreatStringZeroAsEmptyWhenGivingPermission(): void
    {
        $this->app->make(Permission::class)->create(['name' => '0']);

        $this->testUser->givePermissionTo('0');

        $this->assertTrue($this->testUser->hasPermissionTo('0'));
    }
}

class PermissionWithGuardNameAccessor extends PermissionModel
{
    public function getGuardNameAttribute(string $value): string
    {
        return $value === 'stored' ? 'accessed' : $value;
    }
}
