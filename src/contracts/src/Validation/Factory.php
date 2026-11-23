<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Validation;

use Closure;
use Hypervel\Support\Attributes\BootOnly;

interface Factory
{
    /**
     * Create a new Validator instance.
     */
    public function make(array $data, array $rules, array $messages = [], array $attributes = []): Validator;

    /**
     * Register a custom validator extension.
     */
    #[BootOnly(whenShared: true)]
    public function extend(string $rule, Closure|string $extension, ?string $message = null): void;

    /**
     * Register a custom implicit validator extension.
     */
    #[BootOnly(whenShared: true)]
    public function extendImplicit(string $rule, Closure|string $extension, ?string $message = null): void;

    /**
     * Register a custom implicit validator message replacer.
     */
    #[BootOnly(whenShared: true)]
    public function replacer(string $rule, Closure|string $replacer): void;
}
