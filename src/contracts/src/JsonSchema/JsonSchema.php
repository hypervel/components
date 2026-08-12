<?php

declare(strict_types=1);

namespace Hypervel\Contracts\JsonSchema;

use Closure;
use Hypervel\JsonSchema\Types\AnyOfType;
use Hypervel\JsonSchema\Types\ArrayType;
use Hypervel\JsonSchema\Types\BooleanType;
use Hypervel\JsonSchema\Types\IntegerType;
use Hypervel\JsonSchema\Types\NumberType;
use Hypervel\JsonSchema\Types\ObjectType;
use Hypervel\JsonSchema\Types\StringType;
use Hypervel\JsonSchema\Types\Type;
use Hypervel\JsonSchema\Types\UnionType;

interface JsonSchema
{
    /**
     * Create a new object schema instance.
     *
     * @param array<string, Type>|(Closure(JsonSchema): array<string, Type>) $properties
     */
    public function object(Closure|array $properties = []): ObjectType;

    /**
     * Create a new array property instance.
     */
    public function array(): ArrayType;

    /**
     * Create a new string property instance.
     */
    public function string(): StringType;

    /**
     * Create a new integer property instance.
     */
    public function integer(): IntegerType;

    /**
     * Create a new number property instance.
     */
    public function number(): NumberType;

    /**
     * Create a new boolean property instance.
     */
    public function boolean(): BooleanType;

    /**
     * Create a new multi-type union instance.
     *
     * @param array<int, string> $types
     */
    public function union(array $types): UnionType;

    /**
     * Create a new anyOf schema instance.
     *
     * @param array<int, Type>|(Closure(JsonSchema): array<int, Type>) $schemas
     */
    public function anyOf(Closure|array $schemas): AnyOfType;
}
