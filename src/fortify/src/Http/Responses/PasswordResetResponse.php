<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Responses;

use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\JsonResponse;
use Hypervel\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PasswordResetResponse implements PasswordResetResponseContract
{
    /**
     * Create a new response instance.
     */
    public function __construct(
        protected string $status,
    ) {
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        $views = app(Config::class)->boolean('fortify.views');

        return $request->wantsJson()
            ? new JsonResponse(['message' => trans($this->status)], 200)
            : redirect(Fortify::redirects('password-reset', $views ? route('login') : null, $request))->with('status', trans($this->status));
    }
}
