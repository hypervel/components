<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

enum CreationMode
{
    case Create;
    case Validate;
    case Rules;
}
