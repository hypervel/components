<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail\Fixtures;

use Hypervel\Mail\Mailable;

class GreetingMailable extends Mailable
{
    /**
     * Build the message.
     */
    public function build(): static
    {
        return $this->view('greeting');
    }
}
