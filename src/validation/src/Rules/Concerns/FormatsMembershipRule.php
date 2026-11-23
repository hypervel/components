<?php

declare(strict_types=1);

namespace Hypervel\Validation\Rules\Concerns;

use Hypervel\Validation\ValidationRuleParser;

trait FormatsMembershipRule
{
    /**
     * Convert the canonical rule declaration to a validation string.
     *
     * Validation consumes toArray(), so subclasses must customize that declaration.
     *
     * @see ValidationRuleParser::parseParameters
     */
    final public function __toString(): string
    {
        $parameters = $this->toArray();
        $rule = array_shift($parameters);
        $values = array_map(
            static fn (mixed $value): string => '"' . str_replace('"', '""', (string) $value) . '"',
            $parameters,
        );

        return $rule . ':' . implode(',', $values);
    }
}
