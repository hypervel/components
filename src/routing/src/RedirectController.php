<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;

class RedirectController extends Controller
{
    /**
     * Invoke the controller method.
     */
    public function __invoke(Request $request, UrlGenerator $url): RedirectResponse
    {
        $parameters = new Collection($request->route()->parameters());

        $status = $parameters->get('status');

        $destination = $parameters->get('destination');

        $parameters->forget('status')->forget('destination');

        $route = (new Route('GET', $destination, [
            'as' => 'hypervel_route_redirect_destination',
        ]))->bind($request);

        $parameters = $parameters->only(
            $route->getCompiled()->getPathVariables()
        )->all();

        $url = $url->toRoute($route, $parameters, false);

        if (! str_starts_with($destination, '/') && str_starts_with($url, '/')) {
            $url = Str::after($url, '/');
        }

        return new RedirectResponse($url, $status);
    }
}
