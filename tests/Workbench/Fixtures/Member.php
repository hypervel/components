<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Fixtures;

use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Notifications\Notifiable;

class Member extends Authenticatable
{
    use Notifiable;

    protected ?string $table = 'workbench_members';

    protected string $primaryKey = 'member_id';

    protected string $rememberTokenName = 'member_remember_token';

    protected array $fillable = ['name', 'email', 'password'];
}
