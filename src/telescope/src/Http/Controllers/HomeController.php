<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hypervel\Contracts\View\View;
use Hypervel\Telescope\Telescope;

class HomeController
{
    /**
     * Display the Telescope view.
     */
    public function index(): View
    {
        return view('telescope::layout', [
            'cssFile' => Telescope::$useDarkTheme ? 'app-dark.css' : 'app.css',
            'telescopeScriptVariables' => Telescope::scriptVariables(),
        ]);
    }
}
