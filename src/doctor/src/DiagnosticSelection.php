<?php

declare(strict_types=1);

namespace Hypervel\Doctor;

class DiagnosticSelection
{
    /**
     * The normalized selectors diagnostics must match.
     *
     * @var list<string>
     */
    protected readonly array $only;

    /**
     * The normalized selectors diagnostics must not match.
     *
     * @var list<string>
     */
    protected readonly array $except;

    /**
     * The normalized only constraints that must all match.
     *
     * @var list<list<string>>
     */
    protected readonly array $onlyConstraints;

    /**
     * Create a new diagnostic selection instance.
     *
     * @param iterable<array-key, string> $only
     * @param iterable<array-key, string> $except
     * @param list<iterable<array-key, string>> $onlyConstraints
     */
    public function __construct(
        iterable $only = [],
        iterable $except = [],
        array $onlyConstraints = [],
    ) {
        $this->only = self::normalize($only);
        $this->except = self::normalize($except);

        $constraints = [];

        foreach ($onlyConstraints as $constraint) {
            $normalized = self::normalize($constraint);

            if ($normalized !== []) {
                $constraints[] = $normalized;
            }
        }

        if ($constraints === [] && $this->only !== []) {
            $constraints[] = $this->only;
        }

        $this->onlyConstraints = $constraints;
    }

    /**
     * Create a new normalized diagnostic selection.
     *
     * @param iterable<array-key, string> $only
     * @param iterable<array-key, string> $except
     */
    public static function make(iterable $only = [], iterable $except = []): self
    {
        return new self(only: $only, except: $except);
    }

    /**
     * Add another selection constraint.
     *
     * @param iterable<array-key, string> $only
     * @param iterable<array-key, string> $except
     */
    public function constrain(iterable $only = [], iterable $except = []): self
    {
        $only = self::normalize($only);
        $except = self::normalize($except);

        return new self(
            only: $only !== [] ? $only : $this->only,
            except: [...$this->except, ...$except],
            onlyConstraints: [
                ...$this->onlyConstraints,
                ...($only !== [] ? [$only] : []),
            ],
        );
    }

    /**
     * Determine whether a diagnostic matches the selection.
     */
    public function matches(Diagnostic $diagnostic): bool
    {
        foreach ($this->onlyConstraints as $criteria) {
            if (! $this->matchesAny($criteria, $diagnostic)) {
                return false;
            }
        }

        if ($this->except !== [] && $this->matchesAny($this->except, $diagnostic)) {
            return false;
        }

        return true;
    }

    /**
     * Normalize selection values.
     *
     * @param iterable<array-key, string> $values
     * @return list<string>
     */
    protected static function normalize(iterable $values): array
    {
        return array_values(collect($values)
            ->flatMap(static fn (string $value): array => explode(',', $value))
            ->map(static fn (string $part): string => trim($part))
            ->filter(static fn (string $part): bool => $part !== '')
            ->unique()
            ->all());
    }

    /**
     * Determine whether any criterion matches the diagnostic.
     *
     * @param list<string> $criteria
     */
    protected function matchesAny(array $criteria, Diagnostic $diagnostic): bool
    {
        foreach ($criteria as $criterion) {
            if ($this->matchesCriterion($criterion, $diagnostic)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether a criterion matches the diagnostic.
     */
    protected function matchesCriterion(string $criterion, Diagnostic $diagnostic): bool
    {
        return $criterion === $diagnostic::class
            || $criterion === class_basename($diagnostic)
            || $criterion === $diagnostic->group
            || $this->matchesPackage($criterion, $diagnostic);
    }

    /**
     * Determine whether a criterion matches the diagnostic's package.
     */
    protected function matchesPackage(string $criterion, Diagnostic $diagnostic): bool
    {
        $package = $diagnostic->package();

        if ($package === null) {
            return false;
        }

        return $criterion === $package
            || (str_ends_with($criterion, '/*') && str_starts_with($package, substr($criterion, 0, -1)));
    }
}
