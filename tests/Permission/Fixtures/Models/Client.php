<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Contracts\Auth\Access\Authorizable as AuthorizableContract;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\Access\Authorizable;
use Hypervel\Permission\Traits\HasRoles;

class Client extends Model implements AuthorizableContract
{
    use Authorizable;
    use HasRoles;

    /**
     * Required to make clear that the client requires the api guard.
     */
    protected string $guard_name = 'api';

    protected array $fillable = ['name'];

    protected ?string $table = 'clients';

    public bool $timestamps = false;
}
