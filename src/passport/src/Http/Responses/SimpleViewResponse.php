<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Responses;

use Closure;
use Hypervel\Contracts\Container\Transient;
use Hypervel\Http\Request;
use Hypervel\Passport\Contracts\AccountSelectionViewResponse;
use Hypervel\Passport\Contracts\AuthorizationViewResponse;
use Hypervel\Passport\Contracts\DeviceAuthorizationViewResponse;
use Hypervel\Passport\Contracts\DeviceUserCodeViewResponse;
use Hypervel\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

class SimpleViewResponse implements AuthorizationViewResponse, AccountSelectionViewResponse, DeviceAuthorizationViewResponse, DeviceUserCodeViewResponse, Transient
{
    /**
     * An array of arguments that may be passed to the view response and used in the view.
     *
     * @var array<string, mixed>
     */
    protected array $parameters = [];

    /**
     * Create a new response instance.
     *
     * @param (Closure(array<string, mixed>): mixed)|string $view
     */
    public function __construct(
        protected Closure|string $view,
    ) {
    }

    /**
     * Add parameters to response.
     *
     * @param array<string, mixed> $parameters
     */
    public function withParameters(array $parameters = []): static
    {
        $this->parameters = $parameters;

        return $this;
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        if (is_string($this->view)) {
            return response()->view($this->view, $this->parameters);
        }

        return Router::toResponse($request, call_user_func($this->view, $this->parameters));
    }
}
