<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Annotations;

use Attribute;
use InvalidArgumentException;

#[Attribute(Attribute::TARGET_CLASS)]
class LastModified extends Annotation
{
    /**
     * Create a last-modified annotation.
     */
    public function __construct(public string $value)
    {
        // TypeScript-SDK clients reject the whole result for broader date formats.
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?(?:Z|[+-](?:[01]\d|2[0-3]):[0-5]\d)\z/', $value, $matches) !== 1
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw new InvalidArgumentException("LastModified must be a date-time such as 2025-01-12T15:00:58Z (optional fractional seconds, Z or ±HH:MM offset), got '{$value}'");
        }
    }

    /**
     * Get the annotation key.
     */
    public function key(): string
    {
        return 'lastModified';
    }
}
