<?php

declare(strict_types=1);

namespace Hypervel\Permission;

use Hyperf\Contract\ConfigInterface;
use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Psr\Container\ContainerInterface;

class PermissionManager implements Contracts\Factory
{
    protected ?string $roleClass = null;

    protected ?string $permissionClass = null;

    public function __construct(
        protected ContainerInterface $app,
    ) {
        $this->roleClass = $this->getConfig('permission.models.role') ?: Role::class;
        $this->permissionClass = $this->getConfig('permission.models.permission') ?: Permission::class;
    }

    public function getRoleClass(): string
    {
        return $this->roleClass;
    }

    public function getPermissionClass(): string
    {
        return $this->permissionClass;
    }

    protected function getConfig(string $name): ?array
    {
        if (! is_null($name) && $name !== 'null') {
            return $this->app->get(ConfigInterface::class)->get("permission.{$name}");
        }

        return null;
    }
}
