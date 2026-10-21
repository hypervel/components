<?php

declare(strict_types=1);

namespace Hypervel\Head;

use Hypervel\Container\Container;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Head\Concerns\BuildsHead;
use Hypervel\Head\Schema\SchemaFactory;
use Hypervel\Support\Traits\Conditionable;

class HeadBuilder implements Transient
{
    use BuildsHead;
    use Conditionable;

    /**
     * Create a builder for the given head data.
     */
    public function __construct(
        protected HeadData $data,
        protected ?SchemaFactory $schemaFactory = null,
    ) {
    }

    /**
     * Get the head data instance the fluent methods should write to.
     */
    protected function headData(): HeadData
    {
        return $this->data;
    }

    /**
     * Get the schema factory used to resolve schema callbacks.
     */
    protected function schemaFactory(): SchemaFactory
    {
        // Directly constructed builders must also see registered custom schema types.
        return $this->schemaFactory ??= Container::getInstance()->make(SchemaFactory::class);
    }
}
