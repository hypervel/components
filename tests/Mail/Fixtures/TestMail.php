<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail\Fixtures;

use Hypervel\Mail\Mailable;

class TestMail extends Mailable
{
    /**
     * Build the message.
     *
     * @return $this
     */
    public function build(): static
    {
        return $this->view('view');
    }
}
