<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Mail;

use DateInterval;
use DateTimeInterface;

interface MailQueue
{
    /**
     * Queue a new e-mail message for sending.
     */
    public function queue(array|Mailable|string $view, ?string $queue = null): mixed;

    /**
     * Queue a new e-mail message for sending after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, array|Mailable|string $view, ?string $queue = null): mixed;
}
