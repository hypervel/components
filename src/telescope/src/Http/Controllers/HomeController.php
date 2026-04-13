<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Contracts\View\View;

class HomeController
{
    /**
     * Display the Telescope view.
     */
    public function index(): View
    {
        return view('telescope::layout');
    }
}
