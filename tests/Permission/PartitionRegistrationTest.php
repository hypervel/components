<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use BadMethodCallException;
use Hypervel\Contracts\Auth\Access\Gate;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Permission\Contracts\Permission as PermissionContract;
use Hypervel\Permission\Contracts\Role as RoleContract;
use Hypervel\Permission\Exceptions\PermissionPartitionAlreadyConfigured;
use Hypervel\Permission\Exceptions\PermissionPartitionModelNotSupported;
use Hypervel\Permission\Exceptions\PermissionPartitionNotResolved;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\PermissionRegistrar;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use UnitEnum;

class PartitionRegistrationTest extends TestCase
{
    public function testPartitioningIsDisabledByDefault(): void
    {
        $this->resetPermissionRegistrar();

        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertFalse(PermissionRegistrar::partitioningEnabled());
        $this->assertNull(PermissionRegistrar::partitionColumn());
        $this->assertNull($registrar->resolvePartition());
        $this->assertSame('hypervel.permission.cache.roles', $registrar->getCacheKey());
    }

    #[DataProvider('validPartitionValues')]
    public function testItResolvesValidPartitionValues(int|string $value): void
    {
        $this->resetPermissionRegistrar();
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): int|string => $value);

        $partition = $this->app->make(PermissionRegistrar::class)->resolvePartition();

        $this->assertNotNull($partition);
        $this->assertSame('workspace_id', $partition->column);
        $this->assertSame($value, $partition->value);
    }

    /**
     * Get partition values the resolver may return.
     */
    public static function validPartitionValues(): array
    {
        return [
            'integer' => [123],
            'string' => ['workspace-a'],
            'uuid' => ['00000000-0000-0000-0000-000000000001'],
            'integer zero' => [0],
            'string zero' => ['0'],
        ];
    }

    #[DataProvider('unresolvedPartitionValues')]
    public function testItFailsClosedWhenThePartitionCannotBeResolved(?string $value): void
    {
        $this->resetPermissionRegistrar();
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): ?string => $value);

        $this->expectException(PermissionPartitionNotResolved::class);
        $this->expectExceptionMessageIsOrContains('workspace_id');

        $this->app->make(PermissionRegistrar::class)->resolvePartition();
    }

    /**
     * Get resolver results that leave the partition unresolved.
     */
    public static function unresolvedPartitionValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    #[DataProvider('invalidPartitionColumns')]
    public function testItRejectsInvalidPartitionColumns(string $column): void
    {
        $this->resetPermissionRegistrar();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('simple SQL identifier');

        PermissionRegistrar::resolvePartitionUsing($column, fn (): string => 'workspace-a');
    }

    /**
     * Get column names that are not simple SQL identifiers.
     */
    public static function invalidPartitionColumns(): array
    {
        return [
            'empty' => [''],
            'qualified' => ['roles.workspace_id'],
            'expression' => ['lower(workspace_id)'],
            'dash' => ['workspace-id'],
            'leading number' => ['1workspace'],
            'space' => ['workspace id'],
        ];
    }

    public function testItRejectsDuplicateRegistration(): void
    {
        $this->resetPermissionRegistrar();
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');

        $this->expectException(PermissionPartitionAlreadyConfigured::class);

        PermissionRegistrar::resolvePartitionUsing('realm_id', fn (): string => 'realm-a');
    }

    public function testItRejectsRegistrationAfterRegistrarInitialization(): void
    {
        $this->resetPermissionRegistrar();
        $this->app->make(PermissionRegistrar::class);

        $this->expectException(PermissionPartitionAlreadyConfigured::class);

        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');
    }

    public function testProviderRegistrationCanConfigurePartitioningBeforeGateResolution(): void
    {
        $this->resetPermissionRegistrar();
        $this->app->forgetInstance(Gate::class);

        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');

        $this->app->make(Gate::class);

        $partition = $this->app->make(PermissionRegistrar::class)->resolvePartition();

        $this->assertNotNull($partition);
        $this->assertSame('workspace-a', $partition->value);
    }

    public function testResolvingGateBeforeProviderRegistrationMakesLateConfigurationFail(): void
    {
        $this->resetPermissionRegistrar();
        $this->app->forgetInstance(Gate::class);

        $this->app->make(Gate::class);

        $this->expectException(PermissionPartitionAlreadyConfigured::class);

        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');
    }

    public function testFlushStateClearsRegistrationAndRegistrarInitialization(): void
    {
        $this->resetPermissionRegistrar();
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');
        $this->app->make(PermissionRegistrar::class);

        PermissionRegistrar::flushState();

        $this->assertFalse(PermissionRegistrar::partitioningEnabled());
        $this->assertNull(PermissionRegistrar::partitionColumn());

        PermissionRegistrar::resolvePartitionUsing('realm_id', fn (): string => 'realm-a');

        $this->assertSame('realm_id', $this->app->make(PermissionRegistrar::class)->resolvePartition()?->column);
    }

    #[DataProvider('unsupportedPartitionedModels')]
    public function testPartitioningRejectsContractOnlyModels(string $configKey, string $model, string $requiredBase): void
    {
        $this->resetPermissionRegistrar();
        $this->app->make('config')->set($configKey, $model);
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');

        $this->expectException(PermissionPartitionModelNotSupported::class);
        $this->expectExceptionMessageIs("Partitioned permission model `{$model}` must extend `{$requiredBase}`.");

        $this->app->make(PermissionRegistrar::class);
    }

    /**
     * Get contract-only models with the package model they must extend.
     */
    public static function unsupportedPartitionedModels(): array
    {
        return [
            'role' => ['permission.models.role', ContractOnlyRole::class, Role::class],
            'permission' => ['permission.models.permission', ContractOnlyPermission::class, Permission::class],
        ];
    }

    public function testUnpartitionedModeKeepsContractOnlyModelSupport(): void
    {
        $this->resetPermissionRegistrar();
        $this->app->make('config')->set([
            'permission.models.role' => ContractOnlyRole::class,
            'permission.models.permission' => ContractOnlyPermission::class,
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertSame(ContractOnlyRole::class, $registrar->getRoleClass());
        $this->assertSame(ContractOnlyPermission::class, $registrar->getPermissionClass());
    }

    /**
     * Clear the partition registration and the resolved registrar.
     */
    private function resetPermissionRegistrar(): void
    {
        PermissionRegistrar::flushState();
        $this->app->forgetInstance(PermissionRegistrar::class);
    }
}

class ContractOnlyRole extends Model implements RoleContract
{
    public function permissions(): BelongsToMany
    {
        throw new BadMethodCallException;
    }

    public static function findByName(UnitEnum|string $name, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }

    public static function findById(int|string $id, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }

    public static function findOrCreate(UnitEnum|string $name, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }

    public function hasPermissionTo(UnitEnum|int|string|PermissionContract $permission, ?string $guardName = null): bool
    {
        throw new BadMethodCallException;
    }
}

class ContractOnlyPermission extends Model implements PermissionContract
{
    public function roles(): BelongsToMany
    {
        throw new BadMethodCallException;
    }

    public static function findByName(UnitEnum|string $name, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }

    public static function findById(int|string $id, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }

    public static function findOrCreate(UnitEnum|string $name, ?string $guardName): self
    {
        throw new BadMethodCallException;
    }
}
