<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\Mail\Contracts\Factory;
use Hypervel\Mail\Contracts\Mailer as MailerContract;

class MailerFactory
{
    public function __construct(
        protected Factory $manager
    ) {
    }

    public function __invoke(): MailerContract
    {
        return $this->manager->mailer();
    }
}
