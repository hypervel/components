<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

enum CreationMode: string
{
    case Create = 'Create';
    case Validate = 'Validate';
    case Rules = 'Rules';
}
