<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Hyperf\Stubs\Model;

use Hyperf\DbConnection\Model\Relations\MorphPivot;

class UserRoleMorphPivot extends MorphPivot
{
    /**
     * The table associated with the model.
     */
    protected ?string $table = 'user_role';

    /**
     * The attributes that are mass assignable.
     */
    protected array $fillable = ['id', 'user_id', 'role_id', 'created_at', 'updated_at'];
}
