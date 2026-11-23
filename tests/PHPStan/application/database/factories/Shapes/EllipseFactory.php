<?php

declare(strict_types=1);

namespace Database\Factories\Shapes;

use App\Shapes\Ellipse;
use Hypervel\Database\Eloquent\Factories\Factory;

/** @extends Factory<Ellipse> */
class EllipseFactory extends Factory
{
    protected ?string $model = Ellipse::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
