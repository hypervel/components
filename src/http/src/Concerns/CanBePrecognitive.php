<?php

declare(strict_types=1);

namespace Hypervel\Http\Concerns;

use Hypervel\Support\Collection;

trait CanBePrecognitive
{
    /**
     * Filter the given array of rules into an array of rules that are included in precognitive headers.
     */
    public function filterPrecognitiveRules(array $rules): array
    {
        if (! $this->headers->has('Precognition-Validate-Only')) {
            return $rules;
        }

        $validateOnly = explode(',', $this->header('Precognition-Validate-Only'));

        return (new Collection($rules))
            ->filter(fn ($rule, $attribute) => $this->shouldValidatePrecognitiveAttribute((string) $attribute, $validateOnly))
            ->all();
    }

    /**
     * Determine if the given attribute should be validated.
     */
    protected function shouldValidatePrecognitiveAttribute(string $attribute, array $validateOnly): bool
    {
        foreach ($validateOnly as $pattern) {
            $regex = '/^' . str_replace('\*', '[^.]+', preg_quote($pattern, '/')) . '$/';

            if (preg_match($regex, $attribute)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if the request is attempting to be precognitive.
     */
    public function isAttemptingPrecognition(): bool
    {
        return $this->header('Precognition') === 'true';
    }

    /**
     * Determine if the request is precognitive.
     */
    public function isPrecognitive(): bool
    {
        return $this->attributes->get('precognitive', false);
    }
}
