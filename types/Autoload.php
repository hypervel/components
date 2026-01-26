<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\MassPrunable;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\SoftDeletes;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Notifications\HasDatabaseNotifications;

class User extends Authenticatable
{
    use HasDatabaseNotifications;
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use MassPrunable;
    use SoftDeletes;

    protected static string $factory = UserFactory::class;
}

/** @extends Factory<User> */
class UserFactory extends Factory
{
    protected ?string $model = User::class;

    public function definition(): array
    {
        return [];
    }
}

class Post extends Model
{
}

enum UserType
{
}
