<?php

declare(strict_types=1);

namespace Hypervel\Bus;

use Hypervel\Queue\Attributes\ReadsQueueAttributes;

class ChainDefaults
{
    use ReadsQueueAttributes;

    /**
     * Determine if the job leaves the given setting to the chain's default.
     *
     * @param class-string $attribute
     */
    public function appliesTo(object $job, string $attribute, string $property): bool
    {
        $value = $this->getAttributeValue($job, $attribute, $property);

        return $value === null || $value === '';
    }
}
