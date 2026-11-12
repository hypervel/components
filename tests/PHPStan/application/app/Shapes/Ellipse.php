<?php

namespace App\Shapes;

use Database\Factories\Shapes\EllipseFactory;
use Hypervel\Database\Eloquent\Attributes\UseFactory;
use Hypervel\Database\Eloquent\Factories\HasFactory;
use Hypervel\Database\Eloquent\Model;

#[UseFactory(EllipseFactory::class)]
class Ellipse extends Model
{
    /** @use HasFactory<EllipseFactory> */
    use HasFactory;
}
