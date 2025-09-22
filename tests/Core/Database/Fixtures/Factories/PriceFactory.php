<?php

declare(strict_types=1);

namespace Hypervel\Tests\Core\Database\Fixtures\Factories;

use Hypervel\Database\Eloquent\Factories\Factory;

class PriceFactory extends Factory
{
    public function definition()
    {
        return [
            'name' => $this->faker->name(),
        ];
    }
}
