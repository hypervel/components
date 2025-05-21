<?php

declare(strict_types=1);

namespace Hypervel\Validation\Contracts;

use Closure;

interface Factory
{
    /**
     * Create a new Validator instance.
     */
    public function make(array $data, array $rules, array $messages = [], array $attributes = []): Validator;

    /**
     * Register a custom validator extension.
     */
    public function extend(string $rule, Closure|string $extension, ?string $message = null): void;

    /**
     * Register a custom implicit validator extension.
     */
    public function extendImplicit(string $rule, Closure|string $extension, ?string $message = null): void;

    /**
     * Register a custom implicit validator message replacer.
     */
    public function replacer(string $rule, Closure|string $replacer): void;
}
