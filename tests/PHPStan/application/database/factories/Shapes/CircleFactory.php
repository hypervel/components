<?php

declare(strict_types=1);

namespace Database\Factories\Shapes;

use App\Shapes\Circle;
use Hypervel\Database\Eloquent\Factories\Factory;

/** @extends Factory<Circle> */
class CircleFactory extends Factory
{
    protected ?string $model = Circle::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
