<?php

declare(strict_types=1);

namespace Hypervel\Validation\Concerns;

use Egulias\EmailValidator\EmailLexer;
use Egulias\EmailValidator\Result\InvalidEmail;
use Egulias\EmailValidator\Validation\EmailValidation;
use Egulias\EmailValidator\Warning\Warning;

class FilterEmailValidation implements EmailValidation
{
    /**
     * Create a new validation instance.
     *
     * @param null|int $flags the flags to pass to the filter_var function
     */
    public function __construct(
        protected ?int $flags = null
    ) {
    }

    /**
     * Create a new instance which allows any unicode characters in local-part.
     */
    public static function unicode(): static
    {
        return new static(FILTER_FLAG_EMAIL_UNICODE);
    }

    /**
     * Return true if the given email is valid.
     */
    public function isValid(string $email, EmailLexer $emailLexer): bool
    {
        return is_null($this->flags)
            ? filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            : filter_var($email, FILTER_VALIDATE_EMAIL, $this->flags) !== false;
    }

    /**
     * Return the validation error.
     */
    public function getError(): ?InvalidEmail
    {
        return null;
    }

    /**
     * Return the validation warnings.
     *
     * @return Warning[]
     */
    public function getWarnings(): array
    {
        return [];
    }
}
