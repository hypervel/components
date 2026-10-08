<?php

declare(strict_types=1);

namespace Hypervel\Ai\Contracts;

use Hypervel\Ai\Tools\Request;
use Hypervel\Contracts\JsonSchema\JsonSchema;
use Hypervel\JsonSchema\Types\Type;
use Stringable;

interface Tool
{
    /**
     * Get the description of the tool's purpose.
     */
    public function description(): Stringable|string;

    /**
     * Execute the tool.
     */
    public function handle(Request $request): Stringable|string;

    /**
     * Get the tool's schema definition.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array;
}
