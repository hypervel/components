<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Testbench\Factories\UserFactory;

class CommentFactory extends Factory
{
    public function definition(): array
    {
        return [
            'post_id' => PostFactory::new(),
            'user_id' => UserFactory::new(),
            'content' => $this->faker->words(10, true),
        ];
    }
}
