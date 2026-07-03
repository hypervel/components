<?php

declare(strict_types=1);

use Hypervel\Permission\DefaultTeamResolver;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Hypervel\Permission\WildcardPermission;

return [
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
         * The app-owned team model used by the teams feature.
         */
        'team' => null,

        /*
         * The model used when raw IDs are passed to reverse-assignment helpers.
         */
        'default_model' => null,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    /*
     * Register the Gate::before permission check so $user->can('permission') works.
     */
    'register_permission_check_method' => true,

    /*
     * Fire role and permission assignment events when listeners are registered.
     */
    'events_enabled' => false,

    /*
     * Scope roles and assignments by the configured team foreign key.
     */
    'teams' => false,

    /*
     * Resolve the current team id.
     */
    'team_resolver' => DefaultTeamResolver::class,

    /*
     * Allow Passport client-credentials clients to authorize through middleware.
     */
    'use_passport_client_credentials' => false,

    /*
     * Include required permission names in exception messages.
     */
    'display_permission_in_exception' => false,

    /*
     * Include required role names in exception messages.
     */
    'display_role_in_exception' => false,

    /*
     * Enable wildcard permission matching.
     */
    'enable_wildcard_permission' => false,

    /*
     * The class used to parse wildcard permissions.
     */
    'wildcard_permission' => WildcardPermission::class,

    'cache' => [
        'expiration_seconds' => 86400,
        'store' => env('PERMISSION_CACHE_STORE', 'default'),
        'keys' => [
            'roles' => 'hypervel.permission.cache.roles',
            'model_roles' => 'hypervel.permission.cache.model.roles',
            'model_permissions' => 'hypervel.permission.cache.model.permissions',
            'model_token' => 'hypervel.permission.cache.model.token',
        ],
        'column_names_except' => ['created_at', 'updated_at', 'deleted_at'],
    ],
];
