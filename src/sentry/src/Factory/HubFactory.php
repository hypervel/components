<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Factory;

use Hypervel\Foundation\Contracts\Application;
use Hypervel\Sentry\Hub;
use Sentry\State\HubInterface;

class HubFactory
{
    public function __invoke(Application $container): HubInterface
    {
        return new Hub();
    }
}
