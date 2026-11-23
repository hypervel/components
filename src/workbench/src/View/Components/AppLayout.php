<?php

declare(strict_types=1);

namespace Hypervel\Workbench\View\Components;

use Hypervel\Contracts\View\View;
use Hypervel\View\Component;

class AppLayout extends Component
{
    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        // @phpstan-ignore argument.type (AuthServiceProvider adds the Workbench views only when Workbench authentication is enabled.)
        return view('layouts.app');
    }
}
