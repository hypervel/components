<?php

declare(strict_types=1);

namespace Hypervel\Horizon\Http\Controllers;

use Hypervel\Support\Facades\App;

class HomeController extends Controller
{
    /**
     * Single page application catch-all route.
     */
    public function index()
    {
        return view('horizon::layout', [
            'isDownForMaintenance' => App::isDownForMaintenance(),
        ]);
    }
}
