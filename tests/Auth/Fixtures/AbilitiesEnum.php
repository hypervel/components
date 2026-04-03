<?php

declare(strict_types=1);

namespace Hypervel\Tests\Auth\Fixtures;

enum AbilitiesEnum: string
{
    case ViewDashboard = 'view-dashboard';
    case Update = 'update';
}
