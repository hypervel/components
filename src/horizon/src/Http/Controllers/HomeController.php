<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

// use Hypervel\Support\Facades\App;

class HomeController
{
    /**
     * Single page application catch-all route.
     */
    public function index()
    {
        return view('horizon::layout', [
            // isDownForMaintenance 看起來沒有實作
            // TODO: 'isDownForMaintenance' => App::isDownForMaintenance(),
            'isDownForMaintenance' => false,
        ]);
    }
}
