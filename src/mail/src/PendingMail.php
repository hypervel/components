<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hyperf\Conditionable\Conditionable;
use Hypervel\Mail\Contracts\Mailable as MailableContract;
use Hypervel\Mail\Contracts\Mailer as MailerContract;

use function Hyperf\Tappable\tap;

class PendingMail
{
    use Conditionable;

    /**
     * The locale of the message.
     */
    protected ?string $locale = null;

    /**
     * The "to" recipients of the message.
     */
    protected mixed $to = [];

    /**
     * The "cc" recipients of the message.
     */
    protected array $cc = [];

    /**
     * The "bcc" recipients of the message.
     */
    protected array $bcc = [];

    /**
     * Create a new mailable mailer instance.
     */
    public function __construct(
        protected MailerContract $mailer
    ) {
    }

    /**
     * Set the locale of the message.
     */
    public function locale(string $locale): static
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * Set the recipients of the message.
     */
    public function to(mixed $users): static
    {
        $this->to = $users;

        if (! $this->locale && method_exists($users, 'preferredLocale')) {
            $this->locale($users->preferredLocale());
        }

        return $this;
    }

    /**
     * Set the recipients of the message.
     */
    public function cc(mixed $users): static
    {
        $this->cc = $users;

        return $this;
    }

    /**
     * Set the recipients of the message.
     */
    public function bcc(mixed $users): static
    {
        $this->bcc = $users;

        return $this;
    }

    /**
     * Send a new mailable message instance.
     */
    public function send(MailableContract $mailable): ?SentMessage
    {
        return $this->mailer->send($this->fill($mailable));
    }

    /**
     * Send a new mailable message instance synchronously.
     */
    public function sendNow(MailableContract $mailable): ?SentMessage
    {
        return $this->mailer->sendNow($this->fill($mailable));
    }

    /**
     * Populate the mailable with the addresses.
     */
    protected function fill(MailableContract $mailable): MailableContract
    {
        return tap($mailable->to($this->to)
            ->cc($this->cc)
            ->bcc($this->bcc), function (MailableContract $mailable) {
                if ($this->locale) {
                    $mailable->locale($this->locale);
                }
            });
    }
}
