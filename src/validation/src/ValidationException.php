<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Exception;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Validator;
use Hypervel\Contracts\Validation\Validator as ValidatorContract;
use Psr\Http\Message\ResponseInterface;

class ValidationException extends Exception
{
    /**
     * The status code to use for the response.
     */
    public int $status = 422;

    /**
     * The path the client should be redirected to.
     */
    public ?string $redirectTo = null;

    /**
     * Create a new exception instance.
     *
     * @param ValidatorContract $validator the validator instance
     * @param null|ResponseInterface $response the recommended response to send to the client
     * @param string $errorBag the name of the error bag
     */
    public function __construct(
        public ValidatorContract $validator,
        public ?ResponseInterface $response = null,
        public string $errorBag = 'default'
    ) {
        parent::__construct(static::summarize($validator));
    }

    /**
     * Create a new validation exception from a plain array of messages.
     */
    public static function withMessages(array $messages): static
    {
        return new static(tap(Validator::make([], []), function ($validator) use ($messages) {
            foreach ($messages as $key => $value) {
                foreach (Arr::wrap($value) as $message) {
                    $validator->errors()->add($key, $message);
                }
            }
        }));
    }

    /**
     * Create an error message summary from the validation errors.
     */
    protected static function summarize(ValidatorContract $validator): string
    {
        $messages = $validator->errors()->all();

        if (! count($messages) || ! is_string($messages[0])) {
            return $validator->getTranslator()->get('The given data was invalid.');
        }

        $message = array_shift($messages);

        if ($count = count($messages)) {
            $pluralized = $count === 1 ? 'error' : 'errors';

            $message .= ' ' . $validator->getTranslator()->choice("(and :count more {$pluralized})", $count, compact('count'));
        }

        return $message;
    }

    /**
     * Get all of the validation error messages.
     */
    public function errors(): array
    {
        return $this->validator->errors()->messages();
    }

    /**
     * Set the HTTP status code to be used for the response.
     */
    public function status(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    /**
     * Set the error bag on the exception.
     */
    public function errorBag(string $errorBag): static
    {
        $this->errorBag = $errorBag;

        return $this;
    }

    /**
     * Set the URL to redirect to on a validation error.
     */
    public function redirectTo(string $url): static
    {
        $this->redirectTo = $url;

        return $this;
    }

    /**
     * Get the underlying response instance.
     */
    public function getResponse(): ?ResponseInterface
    {
        return $this->response;
    }
}
