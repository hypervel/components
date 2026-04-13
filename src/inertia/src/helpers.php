<?php

declare(strict_types=1);

use Hypervel\Contracts\Support\Arrayable;
use Hypervel\Inertia\Inertia;
use Hypervel\Inertia\Response;
use Hypervel\Inertia\ResponseFactory;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

if (! function_exists('inertia')) {
    /**
     * Inertia helper.
     *
     * @param array<array-key, mixed>|Arrayable<array-key, mixed> $props
     * @return ($component is null ? ResponseFactory : Response)
     */
    function inertia(?string $component = null, array|Arrayable $props = []): ResponseFactory|Response
    {
        $instance = Inertia::getFacadeRoot();

        if ($component) {
            return $instance->render($component, $props);
        }

        return $instance;
    }
}

if (! function_exists('inertia_location')) {
    /**
     * Inertia location helper.
     */
    function inertia_location(string $url): SymfonyResponse
    {
        $instance = Inertia::getFacadeRoot();

        return $instance->location($url);
    }
}
