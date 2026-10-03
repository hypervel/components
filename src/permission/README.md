Permission for Hypervel
===

Documentation: https://hypervel.org/docs/permission

## Differences From Spatie Laravel Permission

- Hypervel adds [denied permissions](https://hypervel.org/docs/permission#denied-permissions). A denied assignment wins over direct or role-granted allows, and the migration stores each assignment's effect in an `is_denied` column, so assigning allow or deny for the same model or role and permission updates the existing assignment. `getDirectPermissions()`, `getPermissionsViaRoles()`, `getAllPermissions()`, and `getPermissionNames()` return effective allowed permissions only.
- Role and permission inputs accept [unit enums](https://hypervel.org/docs/permission#using-enums) as well as backed enums. Unit enums use their case names.
- Hypervel adds opt-in [row partitioning](https://hypervel.org/docs/permission#row-partitioning) through `PermissionRegistrar::resolvePartitionUsing(...)`. The stock migration is unpartitioned; applications that enable partitioning own a [partitioned schema](https://hypervel.org/docs/permission#partitioned-schema).
- The [cache configuration](https://hypervel.org/docs/permission#cache) uses `expiration_seconds` instead of `expiration_time`, with separate named cache keys so role, model-role, model-permission, and assignment-token caches can be invalidated independently.

Ported from: https://github.com/spatie/laravel-permission
