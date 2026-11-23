<?php

declare(strict_types=1);

namespace Database\Factories\Shapes;

use App\Shapes\Triangle;
use Hypervel\Database\Eloquent\Factories\Factory;

/** @extends Factory<Triangle> */
class TriangleFactory extends Factory
{
    protected ?string $model = Triangle::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
