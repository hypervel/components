<?php

declare(strict_types=1);

namespace Hypervel\ExceptionHandler\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;

#[Attribute(Attribute::TARGET_CLASS)]
class ExceptionHandler extends AbstractAnnotation
{
    /**
     * Create a new exception handler annotation instance.
     */
    public function __construct(public string $server = 'http', public int $priority = 0)
    {
    }
}
