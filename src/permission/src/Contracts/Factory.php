<?php

declare(strict_types=1);

namespace Hypervel\Permission\Contracts;

interface Factory
{
    public function getRoleClass();
    public function getPermissionClass();
}
