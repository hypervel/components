<?php

declare(strict_types=1);

namespace Hypervel\Contracts\JsonSchema;

use Closure;

interface JsonSchema
{
    /**
     * Create a new object schema instance.
     *
     * @param array<string, \Hypervel\JsonSchema\Types\Type>|(Closure(JsonSchema): array<string, \Hypervel\JsonSchema\Types\Type>) $properties
     * @return \Hypervel\JsonSchema\Types\ObjectType
     */
    public function object(Closure|array $properties = []);

    /**
     * Create a new array property instance.
     *
     * @return \Hypervel\JsonSchema\Types\ArrayType
     */
    public function array();

    /**
     * Create a new string property instance.
     *
     * @return \Hypervel\JsonSchema\Types\StringType
     */
    public function string();

    /**
     * Create a new integer property instance.
     *
     * @return \Hypervel\JsonSchema\Types\IntegerType
     */
    public function integer();

    /**
     * Create a new number property instance.
     *
     * @return \Hypervel\JsonSchema\Types\NumberType
     */
    public function number();

    /**
     * Create a new boolean property instance.
     *
     * @return \Hypervel\JsonSchema\Types\BooleanType
     */
    public function boolean();
}
