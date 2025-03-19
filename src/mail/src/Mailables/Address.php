<?php

declare(strict_types=1);

namespace Hypervel\Mail\Mailables;

class Address
{
    /**
     * Create a new address instance.
     *
     * @param string $address the recipient's email address
     * @param null|string $name the recipient's name
     */
    public function __construct(
        public string $address,
        public ?string $name = null
    ) {
    }
}
