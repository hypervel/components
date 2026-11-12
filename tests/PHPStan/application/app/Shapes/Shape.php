<?php

namespace App\Shapes;

use Database\Factories\Shapes\ShapeFactory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;

class Shape extends Model
{
    /** @use HasFactory<ShapeFactory> */
    use HasFactory;
}
