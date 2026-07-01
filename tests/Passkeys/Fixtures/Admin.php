<?php

declare(strict_types=1);

namespace Hypervel\Tests\Passkeys\Fixtures;

class Admin extends User
{
    protected ?string $table = 'admins';
}
