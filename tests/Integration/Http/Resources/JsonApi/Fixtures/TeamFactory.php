<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Testbench\Factories\UserFactory;
use Override;

class TeamFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->unique()->company(),
            'user_id' => UserFactory::new(),
            'personal_team' => true,
        ];
    }

    #[Override]
    public function modelName(): string
    {
        return Team::class;
    }
}
