<?php

declare(strict_types=1);

namespace Hypervel\Mail\Mailables;

use InvalidArgumentException;

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
        if (preg_match('/[\r\n]/', $address) > 0) {
            throw new InvalidArgumentException('Email addresses may not contain line break characters.');
        }
    }
}
