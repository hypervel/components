<?php

declare(strict_types=1);

namespace Hypervel\Permission\Support;

use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Contracts\Wildcard;
use Hypervel\Permission\Exceptions\TeamModelNotConfigured;
use Hypervel\Permission\Exceptions\TeamsNotEnabled;
use Hypervel\Permission\PermissionRegistrar;

class Config
{
    /**
     * Get the config repository.
     */
    protected static function repository(): Repository
    {
        return Container::getInstance()->make('config');
    }

    /**
     * Get the permission registrar.
     */
    protected static function registrar(): PermissionRegistrar
    {
        return Container::getInstance()->make(PermissionRegistrar::class);
    }

    /**
     * Determine if teams are enabled.
     */
    public static function teamsEnabled(): bool
    {
        return self::registrar()->teams;
    }

    /**
     * Ensure teams are enabled.
     */
    public static function ensureTeamsEnabled(): void
    {
        if (! self::teamsEnabled()) {
            throw TeamsNotEnabled::create();
        }
    }

    /**
     * @return class-string<Model>
     */
    public static function teamModel(): string
    {
        self::ensureTeamsEnabled();

        $teamModel = self::registrar()->getTeamClass();

        if (! $teamModel) {
            throw TeamModelNotConfigured::create();
        }

        return $teamModel;
    }

    /**
     * Get the model_has_roles table name.
     */
    public static function modelHasRolesTable(): string
    {
        return self::repository()->string('permission.table_names.model_has_roles');
    }

    /**
     * Get the model_has_permissions table name.
     */
    public static function modelHasPermissionsTable(): string
    {
        return self::repository()->string('permission.table_names.model_has_permissions');
    }

    /**
     * Get the role_has_permissions table name.
     */
    public static function roleHasPermissionsTable(): string
    {
        return self::repository()->string('permission.table_names.role_has_permissions');
    }

    /**
     * Get the roles table name.
     */
    public static function rolesTable(): string
    {
        return self::repository()->string('permission.table_names.roles');
    }

    /**
     * Get the permissions table name.
     */
    public static function permissionsTable(): string
    {
        return self::repository()->string('permission.table_names.permissions');
    }

    /**
     * Get the configured storage connection.
     */
    public static function storageConnection(): ?string
    {
        return self::repository()->get('permission.storage.database.connection');
    }

    /**
     * Get the model morph key column.
     */
    public static function morphKey(): string
    {
        return self::repository()->string('permission.column_names.model_morph_key');
    }

    /**
     * Get the team foreign key column.
     */
    public static function teamForeignKey(): string
    {
        return self::registrar()->teamsKey;
    }

    /**
     * Get the default model class for raw permission assignments.
     *
     * @return null|class-string<Model>
     */
    public static function defaultModel(): ?string
    {
        return self::repository()->get('permission.models.default_model');
    }

    /**
     * Get the default auth guard name.
     */
    public static function defaultGuard(): string
    {
        return self::repository()->string('auth.defaults.guard');
    }

    /**
     * @return class-string<Model>
     */
    public static function roleModel(): string
    {
        return self::registrar()->getRoleClass();
    }

    /**
     * @return class-string<Model>
     */
    public static function permissionModel(): string
    {
        return self::registrar()->getPermissionClass();
    }

    /**
     * Determine if permission events are enabled.
     */
    public static function eventsEnabled(): bool
    {
        return self::repository()->boolean('permission.events_enabled');
    }

    /**
     * Determine if Passport client credentials should be checked.
     */
    public static function usePassportClientCredentials(): bool
    {
        return self::repository()->boolean('permission.use_passport_client_credentials');
    }

    /**
     * Determine if role names should be shown in exceptions.
     */
    public static function displayRoleInException(): bool
    {
        return self::repository()->boolean('permission.display_role_in_exception');
    }

    /**
     * Determine if permission names should be shown in exceptions.
     */
    public static function displayPermissionInException(): bool
    {
        return self::repository()->boolean('permission.display_permission_in_exception');
    }

    /**
     * Determine if wildcard permissions are enabled.
     */
    public static function wildcardPermissionsEnabled(): bool
    {
        return self::repository()->boolean('permission.enable_wildcard_permission');
    }

    /**
     * @return class-string<Wildcard>
     */
    public static function wildcardPermissionClass(): string
    {
        return self::repository()->string('permission.wildcard_permission');
    }
}
