<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks\Contracts;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\Connection;

interface WebhookDispatcher
{
    /**
     * Dispatch a webhook for the given event if the app has it configured.
     */
    public function dispatch(Application $application, string $event, array $data = [], ?Connection $connection = null): void;
}
