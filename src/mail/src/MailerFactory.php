<?php

declare(strict_types=1);

namespace Hypervel\Mail;

use Hypervel\Contracts\Mail\Factory;
use Hypervel\Contracts\Mail\Mailer as MailerContract;

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
