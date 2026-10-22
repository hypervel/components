<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Queue;

use Hypervel\Queue\Outbox\OutboxBatchResult;
use Hypervel\Queue\Outbox\OutboxMessage;

interface PublishesOutboxMessages
{
    /**
     * Publish stored messages and identify confirmed acceptance or deferral.
     *
     * @param list<OutboxMessage> $messages
     */
    public function publishOutboxMessages(array $messages): OutboxBatchResult;
}
