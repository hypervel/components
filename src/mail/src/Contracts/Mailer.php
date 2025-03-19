<?php

declare(strict_types=1);

namespace Hypervel\Mail\Contracts;

use Closure;
use Hypervel\Mail\PendingMail;
use Hypervel\Mail\SentMessage;

interface Mailer
{
    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function to(mixed $users): PendingMail;

    /**
     * Begin the process of mailing a mailable class instance.
     */
    public function bcc(mixed $users): PendingMail;

    /**
     * Send a new message with only a raw text part.
     */
    public function raw(string $text, mixed $callback): ?SentMessage;

    /**
     * Send a new message using a view.
     */
    public function send(array|Mailable|string $view, array $data = [], null|Closure|string $callback = null): ?SentMessage;

    /**
     * Send a new message synchronously using a view.
     */
    public function sendNow(array|Mailable|string $mailable, array $data = [], null|Closure|string $callback = null): ?SentMessage;
}
