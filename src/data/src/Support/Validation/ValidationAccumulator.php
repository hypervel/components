<?php

declare(strict_types=1);

namespace Hypervel\Data\Support\Validation;

use Hypervel\Validation\Rules\Exists;
use Hypervel\Validation\Rules\Unique;
use Hypervel\Validation\ValidationRuleParser;

class ValidationAccumulator
{
    /** @var array<string, list<array|object|string>> */
    public array $rules = [];

    /** @var array<string, true> */
    public array $inferredRequiredPaths = [];

    /** @var array<string, array<string, string>|string> */
    public array $messages = [];

    /** @var array<string, string> */
    public array $attributes = [];

    /** @var list<ValidationPath> */
    public array $preservedPaths = [];

    /** @var list<string> */
    public array $additionalFields = [];

    /** @var list<string> */
    public array $allowedSubtrees = [];

    /** @var array<string, true> */
    public array $finishedStructuralPaths = [];

    /** @var array<string, array<string, true>> */
    public array $markerCandidates = [];

    /**
     * Determine if another accumulator has the same compiled output.
     */
    public function equals(self $other): bool
    {
        if (! $this->rulesEqual($this->rules, $other->rules)
            || $this->inferredRequiredPaths !== $other->inferredRequiredPaths
            || $this->messages !== $other->messages
            || $this->attributes !== $other->attributes
        ) {
            return false;
        }

        foreach ($this->preservedPaths as $index => $path) {
            if (! isset($other->preservedPaths[$index])
                || $path->get() !== $other->preservedPaths[$index]->get()
            ) {
                return false;
            }
        }

        return count($this->preservedPaths) === count($other->preservedPaths)
            && $this->additionalFields === $other->additionalFields
            && $this->allowedSubtrees === $other->allowedSubtrees
            && $this->finishedStructuralPaths === $other->finishedStructuralPaths
            && $this->markerCandidates === $other->markerCandidates;
    }

    /**
     * Merge another compilation result into this result.
     */
    public function merge(self $other): void
    {
        $this->rules = array_replace($this->rules, $other->rules);
        $this->inferredRequiredPaths = array_replace(
            $this->inferredRequiredPaths,
            $other->inferredRequiredPaths,
        );
        $this->messages = array_replace($this->messages, $other->messages);
        $this->attributes = array_replace($this->attributes, $other->attributes);
        array_push($this->preservedPaths, ...$other->preservedPaths);
        array_push($this->additionalFields, ...$other->additionalFields);
        array_push($this->allowedSubtrees, ...$other->allowedSubtrees);
        $this->finishedStructuralPaths += $other->finishedStructuralPaths;

        foreach ($other->markerCandidates as $path => $rulePaths) {
            $this->markerCandidates[$path] ??= [];
            $this->markerCandidates[$path] += $rulePaths;
        }
    }

    /**
     * Record a structural marker candidate.
     */
    public function addMarkerCandidate(
        ValidationPath $path,
        ValidationPath $rulePath,
    ): void {
        $key = $path->get();
        $this->markerCandidates[$key] ??= [];
        $this->markerCandidates[$key][$rulePath->get()] = true;
    }

    /**
     * Determine if two ordered rule maps compile to the same Validator input.
     */
    protected function rulesEqual(array $rules, array $otherRules): bool
    {
        if (array_keys($rules) !== array_keys($otherRules)) {
            return false;
        }

        foreach ($rules as $key => $rule) {
            if (! $this->ruleValueEquals($rule, $otherRules[$key])) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if two rule values have the same Validator semantics.
     */
    protected function ruleValueEquals(mixed $rule, mixed $otherRule): bool
    {
        if (is_array($rule) || is_array($otherRule)) {
            return is_array($rule)
                && is_array($otherRule)
                && $this->rulesEqual($rule, $otherRule);
        }

        if (($rule instanceof Exists || $rule instanceof Unique)
            && $rule->queryCallbacks() !== []
        ) {
            return is_object($otherRule)
                && $otherRule::class === $rule::class
                && (string) $rule === (string) $otherRule
                && $rule->queryCallbacks() === $otherRule->queryCallbacks();
        }

        if (ValidationRuleParser::ruleReducesToString($rule)) {
            $rule = (string) $rule;
        }

        if (ValidationRuleParser::ruleReducesToString($otherRule)) {
            $otherRule = (string) $otherRule;
        }

        return $rule === $otherRule;
    }
}
