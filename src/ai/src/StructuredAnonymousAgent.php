<?php

declare(strict_types=1);

namespace Hypervel\Ai;

use Closure;
use Hypervel\Ai\Contracts\HasStructuredOutput;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Laravel\SerializableClosure\SerializableClosure;

class StructuredAnonymousAgent extends AnonymousAgent implements HasStructuredOutput
{
    public ?SerializableClosure $schema;

    /**
     * Create an ad-hoc agent with a structured output schema.
     */
    public function __construct(
        public string $instructions,
        public iterable $messages,
        public iterable $tools,
        ?Closure $schema = null,
    ) {
        $this->schema = $schema instanceof Closure ? new SerializableClosure($schema) : null;
    }

    /**
     * Get the structured output schema.
     */
    public function schema(JsonSchema $schema): array
    {
        return call_user_func($this->schema, $schema);
    }
}
