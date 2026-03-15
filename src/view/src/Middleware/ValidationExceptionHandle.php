<?php

declare(strict_types=1);

namespace Hypervel\View\Middleware;

use Closure;
use Hypervel\Contracts\Support\MessageBag as MessageBagContract;
use Hypervel\Contracts\Support\MessageProvider;
use Hypervel\Http\Request;
use Hypervel\Support\MessageBag;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ValidationExceptionHandle
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            $response = $next($request);
        } catch (Throwable $throwable) {
            if ($throwable instanceof ValidationException) {
                $this->withErrors($request, $throwable->errors(), $throwable->errorBag);

                return redirect($request->session()->previousUrl());
            }

            throw $throwable;
        }

        return $response;
    }

    /**
     * Flash the validation errors to the session.
     */
    public function withErrors(Request $request, mixed $provider, string $key = 'default'): static
    {
        $value = $this->parseErrors($provider);

        $errors = $request->session()->get('errors', new ViewErrorBag());

        if (! $errors instanceof ViewErrorBag) {
            $errors = new ViewErrorBag();
        }

        $request->session()->flash(
            'errors',
            $errors->put($key, $value)
        );

        return $this;
    }

    /**
     * Parse the given errors into a MessageBag instance.
     */
    protected function parseErrors(mixed $provider): MessageBagContract
    {
        if ($provider instanceof MessageProvider) {
            return $provider->getMessageBag();
        }

        return new MessageBag((array) $provider);
    }
}
