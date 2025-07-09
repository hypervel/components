<?php

declare(strict_types=1);

use function Hyperf\Support\env;

return [
    /*
    |--------------------------------------------------------------------------
    | Permission Models
    |--------------------------------------------------------------------------
    |
    | When using the "HasRoles" and "HasPermissions" traits from this package,
    | we need to know which Eloquent models should be used to retrieve your
    | roles and permissions. You may use whatever models you like.
    |
    */

    'models' => [
        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your roles. Of course, it
         * is often just the "Role" model but you may use whatever you like.
         *
         * The model you want to use as a Role model needs to implement the
         * `Hypervel\Permission\Contracts\Role` contract.
         */

        'role' => \Hypervel\Permission\Models\Role::class,

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * Eloquent model should be used to retrieve your permissions. Of course, it
         * is often just the "Permission" model but you may use whatever you like.
         *
         * The model you want to use as a Permission model needs to implement the
         * `Hypervel\Permission\Contracts\Permission` contract.
         */

        'permission' => \Hypervel\Permission\Models\Permission::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Storage Configuration
    |--------------------------------------------------------------------------
    |
    | This configuration determines the database connection that will be used
    | to store permission-related data. You can specify a different connection
    | if you want to store permissions in a separate database.
    |
    */

    'storage' => [
        'database' => [
            'connection' => env('DB_CONNECTION', 'mysql'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Table Names
    |--------------------------------------------------------------------------
    |
    | The following table names are used by the permission package to store
    | roles, permissions and their relationships. You may change these names
    | to match your existing database schema or naming conventions.
    |
    */

    'table_names' => [
        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles. We have chosen a basic
         * default value but you may easily change it to any table you like.
         */

        'roles' => 'roles',

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * table should be used to retrieve your permissions. We have chosen a basic
         * default value but you may easily change it to any table you like.
         */

        'permissions' => 'permissions',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your roles permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */

        'role_has_permissions' => 'role_has_permissions',

        /*
         * When using the "HasPermissions" trait from this package, we need to know which
         * table should be used to retrieve your models permissions. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */

        'owner_has_permissions' => 'owner_has_permissions',

        /*
         * When using the "HasRoles" trait from this package, we need to know which
         * table should be used to retrieve your models roles. We have chosen a
         * basic default value but you may easily change it to any table you like.
         */

        'owner_has_roles' => 'owner_has_roles',
    ],

    /*
    |--------------------------------------------------------------------------
    | Permission Column Names
    |--------------------------------------------------------------------------
    |
    | This configuration allows you to customize the column names used in
    | the pivot tables and relationships. You can modify these to match
    | your database schema or to resolve naming conflicts.
    |
    */

    'column_names' => [
        /*
         * Change this if you want to name the related pivots other than defaults
         */

        'role_pivot_key' => 'role_id',
        'permission_pivot_key' => 'permission_id',

        /*
         * Change this if you want to name the related model primary key other than
         * `owner_id`.
         *
         * For example, this would be nice if your primary keys are all UUIDs. In
         * that case, name this `owner_uuid`.
         */

        'owner_morph_key' => 'owner_id',

        /*
         * The name of the morphable relation for the owner model.
         * This is used to determine the owner type when using polymorphic relations.
         */

        'owner_name' => 'owner',
    ],
    /*
    |--------------------------------------------------------------------------
    | Permission Cache Configuration
    |--------------------------------------------------------------------------
    |
    | By default all permissions are cached for 24 hours to speed up performance.
    | When permissions or roles are updated the cache is flushed automatically.
    | You may optionally indicate a specific cache driver to use for permission
    | and role caching using any of the `store` drivers listed in the cache.php
    | config file. Using 'default' here means to use the `default` set in cache.php.
    |
    */

    'cache' => [
        /*
         * By default all permissions are cached for 24 hours to speed up performance.
         * When permissions or roles are updated the cache is flushed automatically.
         */

        'expiration_seconds' => 86400, // 24 hours in seconds

        'keys' => [
            /*
             * The cache key used to store all roles with their permissions.
             * This is used for efficient role-permission lookups.
             */
            'roles' => 'hypervel.permission.cache.roles',
            /*
             * The cache key prefix used to store roles for individual owners.
             * The actual key will be: {prefix}:{owner_type}:{owner_id}
             */
            'owner_roles' => 'hypervel.permission.cache.owner.roles',
            /*
             * The cache key prefix used to store permissions for individual owners.
             * The actual key will be: {prefix}:{owner_type}:{owner_id}
             */
            'owner_permissions' => 'hypervel.permission.cache.owner.permissions',
        ],

        /*
         * You may optionally indicate a specific cache driver to use for permission and
         * role caching using any of the `store` drivers listed in the cache.php config
         * file. Using 'default' here means to use the `default` set in cache.php.
         */

        'store' => env('PERMISSION_CACHE_STORE', 'default'),
    ],
];
