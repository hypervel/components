<?php

declare(strict_types=1);

namespace Hypervel\Workbench\View\Components;

use Hypervel\Contracts\View\View;
use Hypervel\View\Component;

class GuestLayout extends Component
{
    /**
     * Get the view / contents that represents the component.
     */
    public function render(): View
    {
        return view('layouts.guest');
    }
}
