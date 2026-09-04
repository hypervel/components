<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Http;

use RuntimeException;

class InvalidCastException extends RuntimeException
{
    /**
     * The name of the affected form request.
     */
    public string $request;

    /**
     * The name of the input.
     */
    public string $input;

    /**
     * The name of the cast type.
     */
    public string $castType;

    /**
     * Create a new exception instance.
     */
    public function __construct(FormRequest $request, string $input, string $castType)
    {
        $class = get_class($request);

        parent::__construct("Call to undefined cast [{$castType}] on input [{$input}] in request [{$class}].");

        $this->request = $class;
        $this->input = $input;
        $this->castType = $castType;
    }
}
