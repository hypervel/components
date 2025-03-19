<?php

declare(strict_types=1);

namespace Hypervel\Mail\Contracts;

use DateInterval;
use DateTimeInterface;
use Hypervel\Mail\SentMessage;
use Hypervel\Queue\Contracts\Factory as QueueFactory;

interface Mailable
{
    /**
     * Send the message using the given mailer.
     */
    public function send(Factory|Mailer $mailer): ?SentMessage;

    /**
     * Queue the given message.
     */
    public function queue(QueueFactory $queue): mixed;

    /**
     * Deliver the queued message after (n) seconds.
     */
    public function later(DateInterval|DateTimeInterface|int $delay, QueueFactory $queue): mixed;

    /**
     * Set the recipients of the message.
     */
    public function cc(array|object|string $address, ?string $name = null): static;

    /**
     * Set the recipients of the message.
     */
    public function bcc(array|object|string $address, ?string $name = null): static;

    /**
     * Set the recipients of the message.
     */
    public function to(array|object|string $address, ?string $name = null): static;

    /**
     * Set the locale of the message.
     */
    public function locale(string $locale): static;

    /**
     * Set the name of the mailer that should be used to send the message.
     */
    public function mailer(string $mailer): static;
}
