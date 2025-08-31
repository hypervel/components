<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    use SoftDeletes;
}

class Post extends Model
{
}

enum UserType
{
}
