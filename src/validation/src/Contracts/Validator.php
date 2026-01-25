<?php

declare(strict_types=1);

namespace Hypervel\Validation\Contracts;

use Hypervel\Contracts\Support\MessageProvider;
use Hypervel\Support\MessageBag;
use Hypervel\Contracts\Translation\Translator;
use Hypervel\Validation\ValidationException;

interface Validator extends MessageProvider
{
    /**
     * Run the validator's rules against its data.
     *
     * @throws \Hypervel\Validation\ValidationException
     */
    public function validate(): array;

    /**
     * Get the attributes and values that were validated.
     *
     * @throws \Hypervel\Validation\ValidationException
     */
    public function validated(): array;

    /**
     * Determine if the data fails the validation rules.
     */
    public function fails(): bool;

    /**
     * Get the failed validation rules.
     */
    public function failed(): array;

    /**
     * Add conditions to a given field based on a Closure.
     */
    public function sometimes(array|string $attribute, array|string $rules, callable $callback): static;

    /**
     * Add an after validation callback.
     */
    public function after(callable|string $callback): static;

    /**
     * Get all of the validation error messages.
     */
    public function errors(): MessageBag;

    /**
     * Get the Translator implementation.
     */
    public function getTranslator(): Translator;

    /**
     * Get the data under validation.
     */
    public function getData(): array;

    /**
     * Set the data under validation.
     */
    public function setData(array $data): static;

    /**
     * Get the exception to throw upon failed validation.
     *
     * @return class-string<ValidationException>
     */
    public function getException(): string;
}
