<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Creation;

enum ValidationStrategy: string
{
    case Always = 'always';
    case OnlyRequests = 'only_requests';
    case Disabled = 'disabled';
}
