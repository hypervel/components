<?php

declare(strict_types=1);

namespace Hypervel\View\Events;

use Hyperf\ViewEngine\Contract\ViewInterface;

class ViewRendered
{
    public function __construct(
        public ViewInterface $view
    ) {
    }
}
