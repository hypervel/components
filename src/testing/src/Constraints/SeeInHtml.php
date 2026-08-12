<?php

declare(strict_types=1);

namespace Hypervel\Testing\Constraints;

use PHPUnit\Framework\Constraint\Constraint;
use ReflectionClass;

class SeeInHtml extends Constraint
{
    /**
     * The last value that failed to pass validation.
     */
    protected ?string $failedValue = null;

    /**
     * Create a new constraint instance.
     */
    public function __construct(
        protected string $content,
        protected bool $ordered = false,
        protected bool $negate = false,
    ) {
    }

    /**
     * Determine if the rule passes validation.
     *
     * @param array $values
     */
    public function matches($values): bool
    {
        $normalizedContent = $this->normalize($this->content);

        $position = 0;

        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }

            $normalizedValue = $this->normalize($value);

            if ($normalizedValue === '') {
                $this->failedValue = $value;

                return false;
            }

            $valuePosition = mb_strpos($normalizedContent, $normalizedValue, $position);

            if ($this->negate) {
                if ($valuePosition !== false) {
                    $this->failedValue = $value;

                    return false;
                }

                continue;
            }

            if ($valuePosition === false || $valuePosition < $position) {
                $this->failedValue = $value;

                return false;
            }

            if ($this->ordered) {
                $position = $valuePosition + mb_strlen($normalizedValue);
            }
        }

        return true;
    }

    /**
     * Get the description of the failure.
     *
     * @param array $values
     */
    public function failureDescription($values): string
    {
        if ($this->normalize((string) $this->failedValue) === '') {
            return sprintf(
                'the expected value "%s" contains visible text',
                $this->failedValue
            );
        }

        if ($this->negate) {
            return sprintf(
                '\'%s\' does not contain "%s"',
                $this->content,
                $this->failedValue
            );
        }

        return sprintf(
            '\'%s\' contains "%s"%s',
            $this->content,
            $this->failedValue,
            $this->ordered ? ' in specified order' : ''
        );
    }

    /**
     * Normalize the given value.
     */
    protected function normalize(string $value): string
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES, 'UTF-8'));
        $normalized = preg_replace('/\s+/u', ' ', $value);

        if ($normalized !== null) {
            return $normalized;
        }

        /** @var string $normalized */
        $normalized = preg_replace('/\s+/', ' ', $value);

        return $normalized;
    }

    /**
     * Get a string representation of the object.
     */
    public function toString(): string
    {
        return (new ReflectionClass($this))->name;
    }
}
