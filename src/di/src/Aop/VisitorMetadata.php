<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

class VisitorMetadata
{
    /**
     * The class name of \PhpParser\Node\Stmt\ClassLike.
     */
    public ?string $classLike = null;

    public function __construct(
        public string $className,
        public string $sourceFilePath = ''
    ) {
    }
}
