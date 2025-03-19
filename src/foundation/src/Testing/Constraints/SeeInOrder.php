<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Constraints;

use PHPUnit\Framework\Constraint\Constraint;
use ReflectionClass;

class SeeInOrder extends Constraint
{
    /**
     * The last value that failed to pass validation.
     */
    protected ?string $failedValue = null;

    /**
     * Create a new constraint instance.
     *
     * @param string $content The string under validation
     */
    public function __construct(
        protected string $content
    ) {
    }

    /**
     * Determine if the rule passes validation.
     *
     * @param array $values
     */
    public function matches($values): bool
    {
        $decodedContent = html_entity_decode($this->content, ENT_QUOTES, 'UTF-8');

        $position = 0;

        foreach ($values as $value) {
            if (empty($value)) {
                continue;
            }

            $decodedValue = html_entity_decode($value, ENT_QUOTES, 'UTF-8');

            $valuePosition = mb_strpos($decodedContent, $decodedValue, $position);

            if ($valuePosition === false || $valuePosition < $position) {
                $this->failedValue = $value;

                return false;
            }

            $position = $valuePosition + mb_strlen($decodedValue);
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
        return sprintf(
            'Failed asserting that \'%s\' contains "%s" in specified order.',
            $this->content,
            $this->failedValue
        );
    }

    /**
     * Get a string representation of the object.
     */
    public function toString(): string
    {
        return (new ReflectionClass($this))->name;
    }
}
