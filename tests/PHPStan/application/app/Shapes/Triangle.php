<?php

namespace App\Shapes;

use Database\Factories\Shapes\TriangleFactory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;

class Triangle extends Model
{
    /** @use HasFactory<TriangleFactory> */
    use HasFactory;

    protected static string $factory = TriangleFactory::class;
}
