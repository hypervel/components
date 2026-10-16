<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Types\Type;

interface HasStructuredOutput
{
    /**
     * Get the agent's structured output schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array;
}
