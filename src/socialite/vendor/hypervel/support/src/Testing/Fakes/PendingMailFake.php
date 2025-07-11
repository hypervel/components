<?php

declare(strict_types=1);

namespace Hypervel\Support\Testing\Fakes;

use Hypervel\Mail\Contracts\Mailable;
use Hypervel\Mail\PendingMail;
use Hypervel\Mail\SentMessage;

class PendingMailFake extends PendingMail
{
    /**
     * Send a new mailable message instance.
     */
    public function send(Mailable $mailable): ?SentMessage
    {
        $this->mailer->send($this->fill($mailable));

        return null;
    }

    /**
     * Send a new mailable message instance synchronously.
     */
    public function sendNow(Mailable $mailable): ?SentMessage
    {
        return $this->send($mailable);
    }
}
