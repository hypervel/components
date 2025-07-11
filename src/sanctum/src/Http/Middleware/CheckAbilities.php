<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Middleware;

use Hypervel\Auth\AuthenticationException;
use Hypervel\Sanctum\Exceptions\MissingAbilityException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

class CheckAbilities implements MiddlewareInterface
{
    /**
     * The abilities to check.
     *
     * @var array<string>
     */
    protected array $abilities;

    /**
     * Create a new middleware instance.
     */
    public function __construct(string ...$abilities)
    {
        $this->abilities = $abilities;
    }

    /**
     * Process the incoming request.
     *
     * @throws AuthenticationException
     * @throws MissingAbilityException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $user = $request->getAttribute('user');

        if (! $user || ! method_exists($user, 'currentAccessToken') || ! $user->currentAccessToken()) {
            throw new AuthenticationException();
        }

        foreach ($this->abilities as $ability) {
            if (! $user->tokenCan($ability)) {
                throw new MissingAbilityException($ability);
            }
        }

        return $handler->handle($request);
    }
}
