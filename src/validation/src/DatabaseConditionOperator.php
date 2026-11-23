<?php

declare(strict_types=1);

namespace Hypervel\Validation;

enum DatabaseConditionOperator: string
{
    case Equals = '=';
    case NotEquals = '!=';
    case IsNull = 'is null';
    case IsNotNull = 'is not null';
    case In = 'in';
    case NotIn = 'not in';

    /**
     * Determine if the condition checks membership in a set of values.
     */
    public function isMembership(): bool
    {
        return $this === self::In || $this === self::NotIn;
    }
}
