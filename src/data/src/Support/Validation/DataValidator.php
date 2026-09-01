<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

use Hypervel\Auth\Access\AuthorizationException;
use Hypervel\Auth\Access\Response;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Routing\UrlGenerator;
use Hypervel\Contracts\Validation\Factory;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Data\Contracts\BaseData;
use Hypervel\Data\Support\Creation\ConstructionState;
use Hypervel\Data\Support\Creation\CreationContext;
use Hypervel\Data\Support\Creation\ValidationStrategy;
use Hypervel\Data\Support\DataClass;
use Hypervel\Data\Support\DataClassRepository;
use Hypervel\Foundation\Precognition;
use Hypervel\Http\Request;
use Hypervel\Validation\UnknownFields;
use Hypervel\Validation\ValidationException;
use TypeError;

class DataValidator
{
    /**
     * Create a data validator.
     */
    public function __construct(
        protected readonly Container $container,
        protected readonly Factory $validationFactory,
        protected readonly DataClassRepository $dataClasses,
        protected readonly DataValidationCompiler $compiler,
    ) {
    }

    /**
     * Determine whether validation applies to the root source list.
     *
     * @param array<array-key, mixed> $payloads
     */
    public function shouldValidate(CreationContext $context, array $payloads): bool
    {
        return match ($context->validationStrategy) {
            ValidationStrategy::Always => true,
            ValidationStrategy::OnlyRequests => $this->request($payloads) !== null,
            ValidationStrategy::Disabled => false,
        };
    }

    /**
     * Authorize the root Request before named creation can finish the object.
     *
     * @param class-string<BaseData> $class
     * @param array<array-key, mixed> $payloads
     */
    public function authorize(
        string $class,
        array $payloads,
    ): ?Request {
        $request = $this->request($payloads);
        $dataClass = $this->dataClasses->get($class);

        if ($request === null || ! $dataClass->hasLifecycleMethod('authorize')) {
            return $request;
        }

        $result = $this->container->call("{$class}::authorize");

        if (! is_bool($result) && ! $result instanceof Response) {
            throw new TypeError(sprintf(
                '%s::authorize() must return bool or %s, %s returned.',
                $class,
                Response::class,
                get_debug_type($result),
            ));
        }

        if ($result instanceof Response) {
            $result->authorize();
        } elseif ($result === false) {
            throw new AuthorizationException;
        }

        return $request;
    }

    /**
     * Compile validation for a filled construction state.
     */
    public function compile(ConstructionState $state): CompiledValidation
    {
        return $this->compiler->compile($state);
    }

    /**
     * Compile validation for a filled root collection.
     *
     * @param class-string<BaseData> $dataClass
     */
    public function compileCollection(
        ConstructionState $state,
        string $dataClass,
    ): CompiledValidation {
        return $this->compiler->compileCollection($state, $dataClass);
    }

    /**
     * Validate and replace the state with its filtered payload.
     *
     * @param null|class-string<BaseData> $class
     */
    public function validate(
        ConstructionState $state,
        CompiledValidation $compiled,
        ?Request $request = null,
        ?string $class = null,
    ): void {
        $sourcePayload = $state->payload();
        $dataClass = $this->dataClasses->get(
            $class ?? $state->nodeClass() ?? $state->context->dataClass,
        );
        $validator = $this->validationFactory->make(
            $sourcePayload,
            $compiled->rules,
            $compiled->messages,
            $compiled->attributes,
        );
        $unfilteredRules = null;

        if ($request?->isPrecognitive()) {
            $unfilteredRules = $validator->getRulesWithoutPlaceholders();
            $validator->setRules($request->filterPrecognitiveRules($unfilteredRules));
        }

        $this->configureValidator($validator, $state, $dataClass);

        if (($unknownInput = $state->unknownInput()) !== null) {
            $validator->after(static function (Validator $validator) use (
                $unknownInput,
                $compiled,
                $unfilteredRules,
            ): void {
                UnknownFields::validate(
                    $validator,
                    $unknownInput,
                    $unfilteredRules,
                    $compiled->additionalFields,
                    $compiled->allowedSubtrees,
                );
            });
        }

        if ($request?->isPrecognitive()) {
            $validator->after(Precognition::afterValidationHook($request));
        }

        try {
            $payload = $this->restoreSourceKeyOrder(
                $compiled->restorePreservedValues(
                    $validator->validate(),
                    $sourcePayload,
                ),
                $sourcePayload,
            );
        } catch (ValidationException $exception) {
            $this->configureValidationException($exception, $dataClass);

            throw $exception;
        }

        $state->replacePayload($payload);
    }

    /**
     * Restore surviving payload keys to their source insertion order.
     *
     * @param array<array-key, mixed> $payload
     * @param array<array-key, mixed> $sourcePayload
     * @return array<array-key, mixed>
     */
    protected function restoreSourceKeyOrder(array $payload, array $sourcePayload): array
    {
        $ordered = [];

        foreach ($sourcePayload as $key => $sourceValue) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }

            $value = $payload[$key];
            $ordered[$key] = is_array($sourceValue) && is_array($value)
                ? $this->restoreSourceKeyOrder($value, $sourceValue)
                : $value;
        }

        foreach ($payload as $key => $value) {
            if (! array_key_exists($key, $sourcePayload)) {
                $ordered[$key] = $value;
            }
        }

        return $ordered;
    }

    /**
     * Apply operation-scoped validator hooks.
     */
    protected function configureValidator(
        Validator $validator,
        ConstructionState $state,
        DataClass $dataClass,
    ): void {
        $class = $dataClass->name;
        $validator->stopOnFirstFailure(
            $this->resolveStopOnFirstFailure($dataClass),
        );

        if ($dataClass->hasLifecycleMethod('withValidator')) {
            $this->container->call(
                "{$class}::withValidator",
                ['validator' => $validator],
            );
        }

        foreach ($state->context->withValidatorHooks as $hook) {
            $hook($validator);
        }

        if (! $dataClass->hasLifecycleMethod('after')) {
            return;
        }

        $callbacks = $this->container->call(
            "{$class}::after",
            ['validator' => $validator],
        );

        if (! is_array($callbacks)) {
            throw new TypeError(sprintf(
                '%s::after() must return an array, %s returned.',
                $class,
                get_debug_type($callbacks),
            ));
        }

        /** @var array<array-key, callable|object|string> $callbacks */
        foreach ($callbacks as $callback) {
            $validator->after(
                is_object($callback) && method_exists($callback, 'after')
                    ? $callback->after(...)
                    : $callback,
            );
        }
    }

    /**
     * Apply class-owned validation failure configuration.
     */
    protected function configureValidationException(
        ValidationException $exception,
        DataClass $dataClass,
    ): void {
        $errorBag = $this->resolveStringLifecycleSetting(
            $dataClass,
            'errorBag',
            $dataClass->errorBag,
        );

        if ($errorBag !== null) {
            $exception->errorBag($errorBag);
        }

        $urlGenerator = $this->container->make(UrlGenerator::class);
        $redirect = $this->resolveStringLifecycleSetting(
            $dataClass,
            'redirect',
            $dataClass->redirect,
        );

        if ($redirect !== null && $redirect !== '') {
            $exception->redirectTo($urlGenerator->to($redirect));

            return;
        }

        $redirectRoute = $this->resolveStringLifecycleSetting(
            $dataClass,
            'redirectRoute',
            $dataClass->redirectRoute,
        );

        $exception->redirectTo(
            $redirectRoute !== null && $redirectRoute !== ''
                ? $urlGenerator->route($redirectRoute)
                : $urlGenerator->previous(),
        );
    }

    /**
     * Resolve the effective stop-on-first-failure setting.
     */
    protected function resolveStopOnFirstFailure(DataClass $dataClass): bool
    {
        if (! $dataClass->hasLifecycleMethod('stopOnFirstFailure')) {
            return $dataClass->stopOnFirstFailure;
        }

        $result = $this->container->call(
            "{$dataClass->name}::stopOnFirstFailure",
        );

        if (! is_bool($result)) {
            throw new TypeError(sprintf(
                '%s::stopOnFirstFailure() must return bool, %s returned.',
                $dataClass->name,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * Resolve a method-over-attribute string setting.
     */
    protected function resolveStringLifecycleSetting(
        DataClass $dataClass,
        string $method,
        ?string $attributeValue,
    ): ?string {
        if (! $dataClass->hasLifecycleMethod($method)) {
            return $attributeValue;
        }

        $result = $this->container->call(
            "{$dataClass->name}::{$method}",
        );

        if (! is_string($result)) {
            throw new TypeError(sprintf(
                '%s::%s() must return string, %s returned.',
                $dataClass->name,
                $method,
                get_debug_type($result),
            ));
        }

        return $result;
    }

    /**
     * Find the first Request in the root source list.
     *
     * @param array<array-key, mixed> $payloads
     */
    protected function request(array $payloads): ?Request
    {
        foreach ($payloads as $payload) {
            if ($payload instanceof Request) {
                return $payload;
            }
        }

        return null;
    }
}
