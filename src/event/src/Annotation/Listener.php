<?php

declare(strict_types=1);

namespace Hypervel\Event\Annotation;

use Attribute;
use Hyperf\Di\Annotation\AbstractAnnotation;
use Hypervel\Event\ListenerData;

#[Attribute(Attribute::TARGET_CLASS)]
class Listener extends AbstractAnnotation
{
    /**
     * Create a new listener annotation instance.
     */
    public function __construct(public int $priority = ListenerData::DEFAULT_PRIORITY)
    {
    }
}
