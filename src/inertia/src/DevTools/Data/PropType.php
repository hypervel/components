<?php

declare(strict_types=1);

namespace Hypervel\Inertia\DevTools\Data;

enum PropType: string
{
    case Always = 'always';
    case Defer = 'defer';
    case Optional = 'optional';
    case Merge = 'merge';
    case Scroll = 'scroll';
    case Once = 'once';
}
