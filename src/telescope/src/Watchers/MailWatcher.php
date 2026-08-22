<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Contracts\Foundation\Application;
use Hypervel\Mail\Events\MessageSent;
use Hypervel\Support\Collection;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\AbstractPart;

class MailWatcher extends Watcher
{
    /**
     * Register the watcher.
     */
    public function register(Application $app): void
    {
        $app->make(Dispatcher::class)
            ->listen(MessageSent::class, [$this, 'recordMail']);
    }

    /**
     * Record a mail message was sent.
     */
    public function recordMail(MessageSent $event): void
    {
        if (! Telescope::isRecording()) {
            return;
        }

        $body = $event->message->getBody();

        Telescope::recordMail(IncomingEntry::make([
            'mailable' => $this->getMailable($event),
            'queued' => $this->getQueuedStatus($event),
            'from' => $this->formatAddresses($event->message->getFrom()),
            'replyTo' => $this->formatAddresses($event->message->getReplyTo()),
            'to' => $this->formatAddresses($event->message->getTo()),
            'cc' => $this->formatAddresses($event->message->getCc()),
            'bcc' => $this->formatAddresses($event->message->getBcc()),
            'subject' => $event->message->getSubject(),
            'html' => $body instanceof AbstractPart ? ($event->message->getHtmlBody() ?? $event->message->getTextBody()) : $body,
            'raw' => $event->message->toString(),
        ])->tags($this->tags($event->message, $event->data)));
    }

    /**
     * Get the name of the mailable.
     */
    protected function getMailable(MessageSent $event): string
    {
        if (isset($event->data['__hypervel_notification'])) {
            return $event->data['__hypervel_notification'];
        }

        return $event->data['__telescope_mailable'] ?? '';
    }

    /**
     * Determine whether the mailable was queued.
     */
    protected function getQueuedStatus(MessageSent $event): bool
    {
        if (isset($event->data['__hypervel_notification_queued'])) {
            return $event->data['__hypervel_notification_queued'];
        }

        return $event->data['__telescope_queued'] ?? false;
    }

    /**
     * Convert the given addresses into a readable format.
     */
    protected function formatAddresses(?array $addresses): ?array
    {
        if (is_null($addresses)) {
            return null;
        }

        return Collection::make($addresses)->flatMap(function ($address, $key) {
            if ($address instanceof Address) {
                return [$address->getAddress() => $address->getName()];
            }

            return [$key => $address];
        })->all();
    }

    /**
     * Extract the tags from the message.
     */
    private function tags(mixed $message, array $data): array
    {
        return array_merge(
            array_keys($this->formatAddresses($message->getTo()) ?: []),
            array_keys($this->formatAddresses($message->getCc()) ?: []),
            array_keys($this->formatAddresses($message->getBcc()) ?: []),
            $data['__telescope'] ?? []
        );
    }
}
