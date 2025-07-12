<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Stub;

use Hypervel\Sanctum\HasApiTokens;
use Hypervel\Sanctum\PersonalAccessToken;

class UserWithApiTokens
{
    use HasApiTokens;

    public function tokens()
    {
        return new class {
            public function create(array $attributes)
            {
                $token = new PersonalAccessToken($attributes);
                $token->id = 1;
                return $token;
            }
        };
    }
}
