<?php

declare(strict_types=1);

namespace Hypervel\Telescope\Http\Controllers;

use Hyperf\ViewEngine\Contract\ViewInterface;
use Hypervel\Telescope\Telescope;

class HomeController
{
    /**
     * Display the Telescope view.
     */
    public function index(): ViewInterface
    {
        return view('telescope::layout', [
            'cssFile' => Telescope::$useDarkTheme ? 'app-dark.css' : 'app.css',
            'telescopeScriptVariables' => Telescope::scriptVariables(),
        ]);
    }
}
