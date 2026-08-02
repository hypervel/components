<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Http\Resources\JsonApi\Fixtures;

use Hypervel\Database\Eloquent\Factories\Factory;
use Hypervel\Testbench\Factories\UserFactory;
use Override;

class ProfileFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => UserFactory::new(),
        ];
    }

    #[Override]
    public function modelName(): string
    {
        return Profile::class;
    }
}
