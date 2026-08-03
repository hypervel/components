<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Testbench\Factories\UserFactory;
use Override;

class PostFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
            'title' => $this->faker->word(),
            'content' => $this->faker->words(10, true),
        ];
    }

    #[Override]
    public function modelName(): string
    {
        return Post::class;
    }
}
