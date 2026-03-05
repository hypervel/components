<?php

declare(strict_types=1);

namespace Hypervel\Di\Aop;

use PhpParser\Node;

class VisitorMetadata
{
    public bool $hasConstructor = false;

    public ?Node\Stmt\ClassMethod $constructorNode = null;

    public ?bool $hasExtends = null;

    /**
     * The class name of \PhpParser\Node\Stmt\ClassLike.
     */
    public ?string $classLike = null;

    public function __construct(public string $className)
    {
    }
}
