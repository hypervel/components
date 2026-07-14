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
use stdClass;
use UnexpectedValueException;
use UnitEnum;

class PartitionRegistrationTest extends TestCase
{
    public function testPartitioningIsDisabledByDefault(): void
    {
        PermissionRegistrar::flushState();

        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertFalse(PermissionRegistrar::partitioningEnabled());
        $this->assertNull(PermissionRegistrar::partitionColumn());
        $this->assertNull($registrar->resolvePartition());
        $this->assertSame('hypervel.permission.cache.roles', $registrar->getCacheKey());
    }

    #[DataProvider('validPartitionValues')]
    public function testItResolvesValidPartitionValues(int|string $value): void
    {
        PermissionRegistrar::flushState();
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): int|string => $value);

        $partition = $this->app->make(PermissionRegistrar::class)->resolvePartition();

        $this->assertNotNull($partition);
        $this->assertSame('workspace_id', $partition->column);
        $this->assertSame($value, $partition->value);
    }

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
        PermissionRegistrar::flushState();
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): ?string => $value);

        $this->expectException(PermissionPartitionNotResolved::class);
        $this->expectExceptionMessage('workspace_id');

        $this->app->make(PermissionRegistrar::class)->resolvePartition();
    }

    public static function unresolvedPartitionValues(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    #[DataProvider('invalidPartitionValues')]
    public function testItRejectsInvalidPartitionValues(mixed $value, string $type): void
    {
        PermissionRegistrar::flushState();
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): mixed => $value);

        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($type);

        $this->app->make(PermissionRegistrar::class)->resolvePartition();
    }

    public static function invalidPartitionValues(): array
    {
        return [
            'true' => [true, 'bool'],
            'false' => [false, 'bool'],
            'float' => [1.5, 'float'],
            'array' => [[], 'array'],
            'object' => [new stdClass, stdClass::class],
        ];
    }

    public function testItRejectsAResourcePartitionValue(): void
    {
        $resource = fopen('php://memory', 'r+');

        $this->assertIsResource($resource);

        try {
            PermissionRegistrar::flushState();
            PermissionRegistrar::resolvePartitionUsing('workspace_id', fn () => $resource);

            $this->expectException(UnexpectedValueException::class);
            $this->expectExceptionMessage('resource (stream)');

            $this->app->make(PermissionRegistrar::class)->resolvePartition();
        } finally {
            fclose($resource);
        }
    }

    #[DataProvider('invalidPartitionColumns')]
    public function testItRejectsInvalidPartitionColumns(string $column): void
    {
        PermissionRegistrar::flushState();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('simple SQL identifier');

        PermissionRegistrar::resolvePartitionUsing($column, fn (): string => 'workspace-a');
    }

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
        PermissionRegistrar::flushState();
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');

        $this->expectException(PermissionPartitionAlreadyConfigured::class);

        PermissionRegistrar::resolvePartitionUsing('realm_id', fn (): string => 'realm-a');
    }

    public function testItRejectsRegistrationAfterRegistrarInitialization(): void
    {
        PermissionRegistrar::flushState();
        $this->app->make(PermissionRegistrar::class);

        $this->expectException(PermissionPartitionAlreadyConfigured::class);

        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');
    }

    public function testProviderRegistrationCanConfigurePartitioningBeforeGateResolution(): void
    {
        PermissionRegistrar::flushState();
        $this->app->forgetInstance(Gate::class);

        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');

        $this->app->make(Gate::class);

        $partition = $this->app->make(PermissionRegistrar::class)->resolvePartition();

        $this->assertNotNull($partition);
        $this->assertSame('workspace-a', $partition->value);
    }

    public function testResolvingGateBeforeProviderRegistrationMakesLateConfigurationFail(): void
    {
        PermissionRegistrar::flushState();
        $this->app->forgetInstance(Gate::class);

        $this->app->make(Gate::class);

        $this->expectException(PermissionPartitionAlreadyConfigured::class);

        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');
    }

    public function testFlushStateClearsRegistrationAndRegistrarInitialization(): void
    {
        PermissionRegistrar::flushState();
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');
        $firstRegistrar = $this->app->make(PermissionRegistrar::class);

        PermissionRegistrar::flushState();

        $this->assertFalse(PermissionRegistrar::partitioningEnabled());
        $this->assertNull(PermissionRegistrar::partitionColumn());

        PermissionRegistrar::resolvePartitionUsing('realm_id', fn (): string => 'realm-a');
        $secondRegistrar = $this->app->make(PermissionRegistrar::class);

        $this->assertNotSame($firstRegistrar, $secondRegistrar);
        $this->assertSame('realm_id', $secondRegistrar->resolvePartition()?->column);
    }

    #[DataProvider('unsupportedPartitionedModels')]
    public function testPartitioningRejectsContractOnlyModels(string $configKey, string $model, string $requiredBase): void
    {
        PermissionRegistrar::flushState();
        $this->app->make('config')->set($configKey, $model);
        PermissionRegistrar::resolvePartitionUsing('workspace_id', fn (): string => 'workspace-a');

        $this->expectException(PermissionPartitionModelNotSupported::class);
        $this->expectExceptionMessage($model);
        $this->expectExceptionMessage($requiredBase);

        $this->app->make(PermissionRegistrar::class);
    }

    public static function unsupportedPartitionedModels(): array
    {
        return [
            'role' => ['permission.models.role', ContractOnlyRole::class, Role::class],
            'permission' => ['permission.models.permission', ContractOnlyPermission::class, Permission::class],
        ];
    }

    public function testUnpartitionedModeKeepsContractOnlyModelSupport(): void
    {
        PermissionRegistrar::flushState();
        $this->app->make('config')->set([
            'permission.models.role' => ContractOnlyRole::class,
            'permission.models.permission' => ContractOnlyPermission::class,
        ]);

        $registrar = $this->app->make(PermissionRegistrar::class);

        $this->assertSame(ContractOnlyRole::class, $registrar->getRoleClass());
        $this->assertSame(ContractOnlyPermission::class, $registrar->getPermissionClass());
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
