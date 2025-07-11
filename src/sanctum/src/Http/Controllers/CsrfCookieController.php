<?php

declare(strict_types=1);

namespace Hypervel\Sanctum\Http\Controllers;

use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface;
use Hypervel\Cookie\Contracts\Cookie as CookieContract;
use Hypervel\Session\Contracts\Session;
use Psr\Http\Message\ResponseInterface as PsrResponseInterface;

class CsrfCookieController
{
    public function __construct(
        protected Session $session,
        protected CookieContract $cookie
    ) {
    }

    /**
     * Return an empty response simply to trigger the storage of the CSRF cookie in the browser.
     */
    public function show(RequestInterface $request, ResponseInterface $response): PsrResponseInterface
    {
        if (! $this->session->token()) {
            $this->session->regenerateToken();
        }
        
        $config = config('session');

        $this->cookie->queue(
            'XSRF-TOKEN',
            $this->session->token(),
            $config['lifetime'] ?? 120,
            $config['path'] ?? '/',
            $config['domain'] ?? '',
            $config['secure'] ?? false,
            false, // httpOnly must be false
            false, // raw
            $config['same_site'] ?? null
        );
        
        return $response->json([])->withStatus(200);
    }
}