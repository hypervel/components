<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Support;

use Hypervel\Mcp\Support\ValidationMessages;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\ValidationException;

class ValidationMessagesTest extends TestCase
{
    public function testImplodesAllMessagesIntoASingleOne(): void
    {
        $exception = null;

        try {
            validator(
                ['name' => '', 'email' => 'invalid-email'],
                ['name' => 'required', 'email' => 'required|email']
            )->validate();
        } catch (ValidationException $validationException) {
            $exception = $validationException;
        }

        $messages = ValidationMessages::from($exception);

        $this->assertSame('The name field is required. The email field must be a valid email address.', $messages);
    }

    public function testReturnsAGenericMessageIfNoMessagesAreAvailable(): void
    {
        $exception = new ValidationException(validator([], []));

        $messages = ValidationMessages::from($exception);

        $this->assertSame('The given data was invalid.', $messages);
    }
}
