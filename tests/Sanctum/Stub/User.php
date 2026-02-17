<?php

declare(strict_types=1);

namespace Hypervel\Tests\Sanctum\Stub;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Sanctum\HasApiTokens;

class User extends Model implements Authenticatable
{
    use HasApiTokens;
    use HasFactory;

    protected ?string $table = 'sanctum_test_users';

    protected array $fillable = ['name', 'email', 'password'];

    protected array $hidden = ['password'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
    }

    public function getAuthIdentifierName(): string
    {
        return 'id';
    }

    public function getAuthIdentifier(): mixed
    {
        return $this->id;
    }

    public function getAuthPassword(): string
    {
        return $this->password;
    }
}

class UserFactory extends Factory
{
    protected ?string $model = User::class;

    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
            'email' => $this->faker->unique()->safeEmail(),
            'password' => bcrypt('password'),
        ];
    }
}
