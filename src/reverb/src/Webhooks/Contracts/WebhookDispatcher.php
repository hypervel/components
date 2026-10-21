<?php

declare(strict_types=1);

namespace Hypervel\Reverb\Webhooks\Contracts;

use Hypervel\Reverb\Application;
use Hypervel\Reverb\Contracts\Connection;

interface WebhookDispatcher
{
    /**
     * Dispatch a webhook for the given event if the app has it configured.
     *
     * Called while Reverb handles a WebSocket operation. Report delivery
     * failures instead of throwing them so the operation can complete,
     * but let coroutine cancellation propagate.
     */
    public function dispatch(Application $application, string $event, array $data = [], ?Connection $connection = null): void;
}
