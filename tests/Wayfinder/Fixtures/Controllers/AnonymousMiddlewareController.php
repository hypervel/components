<?php

declare(strict_types=1);

namespace Hypervel\Tests\Wayfinder\Fixtures\Controllers;

use Hypervel\Routing\Controller;

class AnonymousMiddlewareController extends Controller
{
    public function __construct()
    {
        $this->middleware(function ($request, $next) {
            return $next($request);
        });
    }

    public function show()
    {
    }
}
