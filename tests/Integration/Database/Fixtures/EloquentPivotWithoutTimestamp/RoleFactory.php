<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database\Fixtures\EloquentPivotWithoutTimestamp;

use Hypervel\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
        ];
    }
}
