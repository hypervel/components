<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\Concerns;

use Hypervel\Context\RequestContext;
use Hypervel\Http\Request;
use Hypervel\Routing\Route;

trait BindsRouteParameters
{
    /**
     * Make the current request's route carry the given parameters.
     *
     * @param array<string, mixed> $parameters
     */
    protected function bindRouteParameters(array $parameters): Request
    {
        $request = Request::create('/posts');
        $route = (new Route('GET', '/posts', static fn (): null => null))->bind($request);

        foreach ($parameters as $name => $value) {
            $route->setParameter($name, $value);
        }

        $request->setRouteResolver(static fn (): Route => $route);
        RequestContext::set($request);

        return $request;
    }
}
