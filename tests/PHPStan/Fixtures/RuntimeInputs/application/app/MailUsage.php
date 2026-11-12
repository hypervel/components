<?php

declare(strict_types=1);

namespace RuntimeInputs;

use Hypervel\Notifications\Messages\MailMessage;

class MailUsage
{
    public function member(): MailMessage
    {
        return (new MailMessage)->view('mail-page');
    }
}
