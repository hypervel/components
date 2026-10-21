<?php

declare(strict_types=1);

namespace Hypervel\Passport\Http\Responses;

use Hypervel\Contracts\Container\Transient;
use Hypervel\Http\Request;
use Hypervel\Passport\Contracts\ReauthenticationResponse as ReauthenticationResponseContract;
use Symfony\Component\HttpFoundation\Response;

class ReauthenticationResponse implements ReauthenticationResponseContract, Transient
{
    /**
     * The pending authorization and its confirmation return URL.
     *
     * @var array<string, mixed>
     */
    protected array $parameters = [];

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
        $request->session()->put('url.intended', $this->parameters['returnUrl']);

        return to_route('password.confirm');
    }
}
