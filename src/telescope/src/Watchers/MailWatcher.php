<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Watchers;

use Hyperf\Collection\Collection;
use Hypervel\Mail\Events\MessageSent;
use Hypervel\Telescope\IncomingEntry;
use Hypervel\Telescope\Telescope;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Part\AbstractPart;

class MailWatcher extends Watcher
{
    /**
     * Register the watcher.
     */
    public function register(ContainerInterface $app): void
    {
        $app->get(EventDispatcherInterface::class)
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

        /* @phpstan-ignore-next-line */
        $body = $event->message->getBody();

        Telescope::recordMail(IncomingEntry::make([
            'mailable' => $this->getMailable($event),
            'queued' => $this->getQueuedStatus($event),
            'from' => $this->formatAddresses($event->message->getFrom()), // @phpstan-ignore-line
            'replyTo' => $this->formatAddresses($event->message->getReplyTo()), // @phpstan-ignore-line
            'to' => $this->formatAddresses($event->message->getTo()), // @phpstan-ignore-line
            'cc' => $this->formatAddresses($event->message->getCc()), // @phpstan-ignore-line
            'bcc' => $this->formatAddresses($event->message->getBcc()), // @phpstan-ignore-line
            'subject' => $event->message->getSubject(), // @phpstan-ignore-line
            'html' => $body instanceof AbstractPart ? ($event->message->getHtmlBody() ?? $event->message->getTextBody()) : $body, // @phpstan-ignore-line
            'raw' => $event->message->toString(), // @phpstan-ignore-line
        ])->tags($this->tags($event->message, $event->data))); // @phpstan-ignore-line
    }

    /**
     * Get the name of the mailable.
     */
    protected function getMailable(MessageSent $event): string
    {
        if (isset($event->data['__laravel_notification'])) {
            return $event->data['__laravel_notification'];
        }

        return $event->data['__telescope_mailable'] ?? '';
    }

    /**
     * Determine whether the mailable was queued.
     */
    protected function getQueuedStatus(MessageSent $event): bool
    {
        if (isset($event->data['__laravel_notification_queued'])) {
            return $event->data['__laravel_notification_queued'];
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
            array_keys($this->formatAddresses($message->getTo()) ?: []), // @phpstan-ignore-line
            array_keys($this->formatAddresses($message->getCc()) ?: []), // @phpstan-ignore-line
            array_keys($this->formatAddresses($message->getBcc()) ?: []), // @phpstan-ignore-line
            $data['__telescope'] ?? []
        );
    }
}
