<?php

declare(strict_types=1);

namespace Hypervel\Tests\Routing\Fixtures;

enum AbilityBackedEnum: string
{
    case AccessRoute = 'access-route';
    case NotAccessRoute = 'not-access-route';
}
