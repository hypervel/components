<?php

declare(strict_types=1);

use Hypervel\Permission\DefaultTeamResolver;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;

return [
    /*
    |--------------------------------------------------------------------------
    | Permission Models
    |--------------------------------------------------------------------------
    |
    | These models back the package's role and permission records. The team
    | and default models may remain null when their related behavior is unused.
    |
    */

    'models' => [
        /*
         * The model used to retrieve permissions.
         */
        'permission' => Permission::class,

        /*
         * The model used to retrieve roles.
         */
        'role' => Role::class,

        /*
         * The app-owned team model used by the teams feature. Set to null when
         * teams are disabled or the application does not expose a team model.
         */
        'team' => null,

        /*
         * The model used when raw IDs are passed to reverse-assignment helpers.
         * Set to null to use the authenticated guard's user model.
         */
        'default_model' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table and Column Names
    |--------------------------------------------------------------------------
    |
    | Runtime relationships and the published migrations both use these names.
    | Keep any customized values aligned with the application's schema.
    |
    */

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        /*
         * Set these pivot keys to null to use role_id and permission_id.
         */
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Checks and Assignment Events
    |--------------------------------------------------------------------------
    |
    | The package may register its Gate permission hook and dispatch the role
    | and permission attached and detached events. Events are only constructed
    | when a listener is registered for the corresponding event class.
    |
     */

    'register_permission_check_method' => true,

    'events_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Teams
    |--------------------------------------------------------------------------
    |
    | Teams scope roles and assignments by the configured team foreign key.
    | A custom resolver must implement the PermissionsTeamResolver contract.
    |
     */

    'teams' => false,

    'team_resolver' => DefaultTeamResolver::class,

    /*
     * Allow Passport client-credentials clients to authorize through middleware.
     */
    'use_passport_client_credentials' => false,

    /*
    |--------------------------------------------------------------------------
    | Exception Messages
    |--------------------------------------------------------------------------
    |
    | These options expose required role or permission names in authorization
    | exception messages. Leave them disabled when those names are sensitive.
    |
     */

    'display_permission_in_exception' => false,

    'display_role_in_exception' => false,

    /*
    |--------------------------------------------------------------------------
    | Wildcard Permissions
    |--------------------------------------------------------------------------
    |
    | Wildcard matching is disabled by default. A custom parser must implement
    | the Hypervel\Permission\Contracts\Wildcard contract.
    |
     */

    'enable_wildcard_permission' => false,

    // 'wildcard_permission' => Hypervel\Permission\WildcardPermission::class,

    /*
    |--------------------------------------------------------------------------
    | Permission Cache
    |--------------------------------------------------------------------------
    |
    | Permission data is cached for 24 hours by default. The named cache keys
    | separate catalog and assignment data so each can be invalidated precisely.
    | Column exclusions reduce the serialized catalog without hiding required
    | model, partition, or team columns.
    |
    */

    'cache' => [
        'expiration_seconds' => 86400,
        'store' => env('PERMISSION_CACHE_STORE', 'default'),
        'keys' => [
            'roles' => 'hypervel.permission.cache.roles', // Role and permission catalog.
            'model_roles' => 'hypervel.permission.cache.model.roles', // Per-model role assignments.
            'model_permissions' => 'hypervel.permission.cache.model.permissions', // Per-model direct permissions.
            'model_token' => 'hypervel.permission.cache.model.token', // Assignment-version tokens.
        ],
        'column_names_except' => ['created_at', 'updated_at', 'deleted_at'],
    ],
];
