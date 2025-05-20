<?php

declare(strict_types=1);

namespace Hypervel\Validation;

use Hypervel\Validation\Contracts\Validator;

/**
 * Provides default implementation of ValidatesWhenResolved contract.
 */
trait ValidatesWhenResolvedTrait
{
    /**
     * Validate the class instance.
     */
    public function validateResolved(): void
    {
        $this->prepareForValidation();

        if (! $this->passesAuthorization()) {
            $this->failedAuthorization();
        }

        $instance = $this->getValidatorInstance();

        if ($instance->fails()) {
            $this->failedValidation($instance);
        }

        $this->passedValidation();
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
    }

    /**
     * Get the validator instance for the request.
     */
    protected function getValidatorInstance(): Validator
    {
        return $this->validator();
    }

    /**
     * Handle a passed validation attempt.
     */
    protected function passedValidation(): void
    {
    }

    /**
     * Handle a failed validation attempt.
     *
     * @throws ValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        $exception = $validator->getException();

        throw new $exception($validator);
    }

    /**
     * Determine if the request passes the authorization check.
     */
    protected function passesAuthorization(): bool
    {
        if (method_exists($this, 'authorize')) {
            return $this->authorize();
        }

        return true;
    }

    /**
     * Handle a failed authorization attempt.
     *
     * @throws UnauthorizedException
     */
    protected function failedAuthorization(): void
    {
        throw new UnauthorizedException();
    }
}
