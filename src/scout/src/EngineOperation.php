<?php

declare(strict_types=1);

namespace Hypervel\Scout;

use Hypervel\Database\Eloquent\Model;

final readonly class EngineOperation
{
    /**
     * Create an engine operation description.
     *
     * @param class-string<Model> $modelClass
     */
    public function __construct(
        public string $operation,
        public string $engineName,
        public string $modelClass,
        public string $index,
        public ?int $modelCount = null
    ) {
    }
}
