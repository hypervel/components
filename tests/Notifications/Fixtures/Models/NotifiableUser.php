<?php

declare(strict_types=1);

namespace Hypervel\Tests\Notifications\Fixtures\Models;

use Hypervel\Database\Eloquent\Model;
use Hypervel\Notifications\Notifiable;

class NotifiableUser extends Model
{
    use Notifiable;

    protected ?string $table = 'users';

    public bool $timestamps = false;
}
