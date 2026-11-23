<?php

namespace App\Casts;

enum BasicEnumeration
{
    // Hypervel: PHPStan treats an enum without cases as having no values, which any type accepts.
    case Basic;
}
