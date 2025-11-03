<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

class HomeController
{
    /**
     * Single page application catch-all route.
     */
    public function index()
    {
        return view('horizon::layout', [
            'isDownForMaintenance' => false,
        ]);
    }
}
