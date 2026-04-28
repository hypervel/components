<?php

declare(strict_types=1);

namespace Hypervel\Routing;

use Hypervel\Contracts\Routing\ResponseFactory;
use Symfony\Component\HttpFoundation\Response;

class ViewController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected ResponseFactory $response,
    ) {
    }

    /**
     * Invoke the controller method.
     */
    public function __invoke(mixed ...$args): Response
    {
        $routeParameters = array_filter($args, function ($key) {
            return ! in_array($key, ['view', 'data', 'status', 'headers']);
        }, ARRAY_FILTER_USE_KEY);

        $args['data'] = array_merge($args['data'], $routeParameters);

        return $this->response->view(
            $args['view'],
            $args['data'],
            $args['status'],
            $args['headers']
        );
    }

    /**
     * Execute an action on the controller.
     */
    public function callAction(string $method, array $parameters): Response
    {
        return $this->{$method}(...$parameters);
    }
}
