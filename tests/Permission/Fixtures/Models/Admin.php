<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

class Admin extends User
{
    protected ?string $table = 'admins';

    protected array $touches = ['roles', 'permissions'];
}
