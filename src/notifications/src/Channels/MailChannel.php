<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Channels;

use Closure;
use Hypervel\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hypervel\Contracts\Mail\Factory as MailFactory;
use Hypervel\Contracts\Mail\Mailable;
use Hypervel\Contracts\Queue\ShouldQueue;
use Hypervel\Mail\Markdown;
use Hypervel\Mail\Message;
use Hypervel\Mail\SentMessage;
use Hypervel\Notifications\Messages\MailMessage;
use Hypervel\Notifications\Notification;
use Hypervel\Support\Arr;
use Hypervel\Support\Str;
use RuntimeException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\Header\TagHeader;

class MailChannel
{
    /**
     * Create a new mail channel instance.
     */
    public function __construct(
        protected MailFactory $mailer,
        protected Markdown $markdown
    ) {
    }

    /**
     * Send the given notification.
     */
    public function send(mixed $notifiable, Notification $notification): ?SentMessage
    {
        if (! method_exists($notification, 'toMail')) {
            throw new RuntimeException('Notification is missing `toMail` method.');
        }

        /* @phpstan-ignore-next-line */
        $message = $notification->toMail($notifiable);

        if (! $notifiable->routeNotificationFor('mail', $notification)
            && ! $message instanceof Mailable
        ) {
            return null;
        }

        if ($message instanceof Mailable) {
            return $message->send($this->mailer);
        }

        return $this->mailer->mailer($message->mailer ?? null)->send(
            $this->buildView($message),
            array_merge($message->data(), $this->additionalMessageData($notification)),
            $this->messageBuilder($notifiable, $notification, $message)
        );
    }

    /**
     * Get the mailer Closure for the message.
     */
    protected function messageBuilder(mixed $notifiable, Notification $notification, MailMessage $message): Closure
    {
        return function ($mailMessage) use ($notifiable, $notification, $message) {
            $this->buildMessage($mailMessage, $notifiable, $notification, $message);
        };
    }

    /**
     * Build the notification's view.
     */
    protected function buildView(MailMessage $message): array|string
    {
        if ($message->view) {
            return $message->view;
        }

        return [
            'html' => $this->buildMarkdownHtml($message),
            'text' => $this->buildMarkdownText($message),
        ];
    }

    /**
     * Build the HTML view for a Markdown message.
     */
    protected function buildMarkdownHtml(MailMessage $message): Closure
    {
        return fn ($data) => $this->markdownRenderer($message)->render(
            $message->markdown,
            array_merge($data, $message->data()),
        );
    }

    /**
     * Build the text view for a Markdown message.
     */
    protected function buildMarkdownText(MailMessage $message): Closure
    {
        return fn ($data) => $this->markdownRenderer($message)->renderText(
            $message->markdown,
            array_merge($data, $message->data()),
        );
    }

    /**
     * Get the Markdown implementation.
     */
    protected function markdownRenderer(MailMessage $message): Markdown
    {
        $config = ApplicationContext::getContainer()
            ->get(ConfigInterface::class);

        $theme = $message->theme ?? $config->get('mail.markdown.theme', 'default');

        return $this->markdown->theme($theme);
    }

    /**
     * Get additional meta-data to pass along with the view data.
     */
    protected function additionalMessageData(Notification $notification): array
    {
        return [
            '__laravel_notification_id' => $notification->id,
            '__laravel_notification' => get_class($notification),
            '__laravel_notification_queued' => in_array(
                ShouldQueue::class,
                class_implements($notification)
            ),
        ];
    }

    /**
     * Build the mail message.
     */
    protected function buildMessage(Message $mailMessage, mixed $notifiable, Notification $notification, MailMessage $message): void
    {
        $this->addressMessage($mailMessage, $notifiable, $notification, $message);

        /* @phpstan-ignore-next-line */
        $mailMessage->subject($message->subject ?: Str::title(
            Str::snake(class_basename($notification), ' ')
        ));

        $this->addAttachments($mailMessage, $message);

        if (! is_null($message->priority)) {
            $mailMessage->priority($message->priority);
        }

        if ($message->tags) {
            foreach ($message->tags as $tag) {
                $mailMessage->getHeaders()->add(new TagHeader($tag));
            }
        }

        if ($message->metadata) {
            foreach ($message->metadata as $key => $value) {
                $mailMessage->getHeaders()->add(new MetadataHeader($key, $value));
            }
        }

        $this->runCallbacks($mailMessage, $message);
    }

    /**
     * Address the mail message.
     */
    protected function addressMessage(Message $mailMessage, mixed $notifiable, Notification $notification, MailMessage $message): void
    {
        $this->addSender($mailMessage, $message);

        $mailMessage->to($this->getRecipients($notifiable, $notification, $message));

        if (! empty($message->cc)) {
            foreach ($message->cc as $cc) {
                $mailMessage->cc($cc[0], Arr::get($cc, 1));
            }
        }

        if (! empty($message->bcc)) {
            foreach ($message->bcc as $bcc) {
                $mailMessage->bcc($bcc[0], Arr::get($bcc, 1));
            }
        }
    }

    /**
     * Add the "from" and "reply to" addresses to the message.
     */
    protected function addSender(Message $mailMessage, MailMessage $message): void
    {
        if (! empty($message->from)) {
            $mailMessage->from($message->from[0], Arr::get($message->from, 1));
        }

        if (! empty($message->replyTo)) {
            foreach ($message->replyTo as $replyTo) {
                $mailMessage->replyTo($replyTo[0], Arr::get($replyTo, 1));
            }
        }
    }

    /**
     * Get the recipients of the given message.
     */
    protected function getRecipients(mixed $notifiable, Notification $notification, MailMessage $message): mixed
    {
        if (is_string($recipients = $notifiable->routeNotificationFor('mail', $notification))) {
            $recipients = [$recipients];
        }

        return collect($recipients)->mapWithKeys(function ($recipient, $email) {
            return is_numeric($email)
                ? [$email => (is_string($recipient) ? $recipient : $recipient->email)]
                : [$email => $recipient];
        })->all();
    }

    /**
     * Add the attachments to the message.
     */
    protected function addAttachments(Message $mailMessage, MailMessage $message): void
    {
        foreach ($message->attachments as $attachment) {
            $mailMessage->attach($attachment['file'], $attachment['options']);
        }

        foreach ($message->rawAttachments as $attachment) {
            $mailMessage->attachData($attachment['data'], $attachment['name'], $attachment['options']);
        }
    }

    /**
     * Run the callbacks for the message.
     */
    protected function runCallbacks(Message $mailMessage, MailMessage $message): static
    {
        foreach ($message->callbacks as $callback) {
            $callback($mailMessage->getSymfonyMessage());
        }

        return $this;
    }
}
