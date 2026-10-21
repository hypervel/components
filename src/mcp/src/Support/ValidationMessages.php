<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Support;

use Hypervel\Support\Facades\Validator;
use Hypervel\Validation\ValidationException;

class ValidationMessages
{
    /**
     * Join validation messages for a protocol error.
     */
    public static function from(ValidationException $exception): string
    {
        $messages = collect($exception->errors())->flatten()->all();

        if (count($messages) === 0 || ! is_string($messages[0])) {
            $translator = Validator::getTranslator();

            return $translator->get('The given data was invalid.');
        }

        return implode(' ', $messages);
    }
}
