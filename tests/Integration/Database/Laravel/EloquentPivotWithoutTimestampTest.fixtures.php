<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Laravel\EloquentPivotWithoutTimestampTest;

use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Relations\BelongsToMany;
use Hypervel\Database\Eloquent\Relations\Pivot;
use Hypervel\Database\Schema\Blueprint;
use Hypervel\Foundation\Auth\User as Authenticatable;
use Hypervel\Support\Facades\Schema;
use Hypervel\Testbench\Factories\UserFactory;

#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    use HasFactory;

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class)
            ->withPivot('notes')
            ->using(UserRole::class)
            ->withTimestamps(updatedAt: false);
    }
}

#[UseFactory(RoleFactory::class)]
class Role extends Model
{
    use HasFactory;

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)
            ->withPivot('notes')
            ->using(UserRole::class);
    }
}

class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
        ];
    }
}

class UserRole extends Pivot
{
    protected ?string $table = 'role_user';

    public function getUpdatedAtColumn(): ?string
    {
        return null;
    }
}

function migrate()
{
    Schema::create('roles', function (Blueprint $table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    Schema::create('role_user', function (Blueprint $table) {
        $table->foreignId('user_id');
        $table->foreignId('role_id');
        $table->text('notes');
        $table->timestamp('created_at')->nullable();
    });
}
