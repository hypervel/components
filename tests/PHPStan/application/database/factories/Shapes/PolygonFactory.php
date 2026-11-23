<?php

declare(strict_types=1);

namespace Database\Factories\Shapes;

use App\Shapes\Polygon;
use Hypervel\Database\Eloquent\Factories\Factory;

/**
 * @template TModel of Polygon = Polygon
 * @extends Factory<TModel>
 */
class PolygonFactory extends Factory
{
    protected ?string $model = Polygon::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [];
    }
}
