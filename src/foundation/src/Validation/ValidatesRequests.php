<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Validation;

use Hypervel\Contracts\Validation\Factory;
use Hypervel\Contracts\Validation\Validator;
use Hypervel\Foundation\Precognition;
use Hypervel\Http\Request;
use Hypervel\Validation\ValidationException;

trait ValidatesRequests
{
    /**
     * Run the validation routine against the given validator.
     *
     * @throws ValidationException
     */
    public function validateWith(Validator|array $validator, ?Request $request = null): array
    {
        $request = $request ?: request();

        if (is_array($validator)) {
            $validator = $this->getValidationFactory()->make($request->all(), $validator);
        }

        if ($request->isPrecognitive()) {
            $validator->after(Precognition::afterValidationHook($request))
                ->setRules(
                    $request->filterPrecognitiveRules($validator->getRulesWithoutPlaceholders())
                );
        }

        return $validator->validate();
    }

    /**
     * Validate the given request with the given rules.
     *
     * @throws ValidationException
     */
    public function validate(Request $request, array $rules, array $messages = [], array $attributes = []): array
    {
        $validator = $this->getValidationFactory()->make(
            $request->all(),
            $rules,
            $messages,
            $attributes
        );

        if ($request->isPrecognitive()) {
            $validator->after(Precognition::afterValidationHook($request))
                ->setRules(
                    $request->filterPrecognitiveRules($validator->getRulesWithoutPlaceholders())
                );
        }

        return $validator->validate();
    }

    /**
     * Validate the given request with the given rules.
     *
     * @throws ValidationException
     */
    public function validateWithBag(string $errorBag, Request $request, array $rules, array $messages = [], array $attributes = []): array
    {
        try {
            return $this->validate($request, $rules, $messages, $attributes);
        } catch (ValidationException $e) {
            $e->errorBag = $errorBag;

            throw $e;
        }
    }

    /**
     * Get a validation factory instance.
     */
    protected function getValidationFactory(): Factory
    {
        return app(Factory::class);
    }
}
