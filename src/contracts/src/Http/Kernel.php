<?php

declare(strict_types=1);

namespace Hypervel\Contracts\Http;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

interface Kernel
{
    /**
     * Bootstrap the application for HTTP requests.
     */
    public function bootstrap(): void;

    /**
     * Handle an incoming HTTP request.
     */
    public function handle(Request $request): Response;

    /**
     * Perform any final actions for the request lifecycle.
     */
    public function terminate(Request $request, Response $response): void;

    /**
     * Get the application's route middleware groups.
     *
     * @return array<string, array<int, mixed>>
     */
    public function getMiddlewareGroups(): array;

    /**
     * Get the application instance.
     */
    public function getApplication(): Application;
}
