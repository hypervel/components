<?php

declare(strict_types=1);

namespace Hypervel\Tests\Database\Laravel\Fixtures\Factories\Money;

use Hypervel\Database\Eloquent\Factories\Factory;

class PriceFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}
