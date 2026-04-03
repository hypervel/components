<?php

declare(strict_types=1);

namespace Workbench\App\View\Components;

use Closure;
use Hypervel\Contracts\View\View;
use Hypervel\View\Component;

class Alert extends Component
{
    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        return '<div>Alert Component</div>';
    }
}
