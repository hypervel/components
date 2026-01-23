<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Stubs;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;

class User extends Model
{
    use HasFactory;

    protected ?string $table = 'foundation_test_users';

    protected array $fillable = ['name', 'email'];

    protected static function newFactory(): UserFactory
    {
        return UserFactory::new();
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
        ];
    }
}
