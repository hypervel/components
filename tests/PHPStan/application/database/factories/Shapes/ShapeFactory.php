<?php

declare(strict_types=1);

namespace Database\Factories\Shapes;

use App\Shapes\Shape;
use Hypervel\Database\Eloquent\Factories\Factory;

/** @extends Factory<Shape> */
class ShapeFactory extends Factory
{
    protected ?string $model = Shape::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
