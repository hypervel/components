<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Responses;

use Hypervel\Fortify\Contracts\ConfirmPasswordViewResponse;
use Hypervel\Fortify\Contracts\LoginViewResponse;
use Hypervel\Fortify\Contracts\RegisterViewResponse;
use Hypervel\Fortify\Contracts\RequestPasswordResetLinkViewResponse;
use Hypervel\Fortify\Contracts\ResetPasswordViewResponse;
use Hypervel\Fortify\Contracts\TwoFactorChallengeViewResponse;
use Hypervel\Fortify\Contracts\VerifyEmailViewResponse;
use Hypervel\Http\Request;
use Hypervel\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

class SimpleViewResponse implements LoginViewResponse, ResetPasswordViewResponse, RegisterViewResponse, RequestPasswordResetLinkViewResponse, TwoFactorChallengeViewResponse, VerifyEmailViewResponse, ConfirmPasswordViewResponse
{
    /**
     * The name of the view or the callable used to generate the view.
     *
     * @var callable|string
     */
    protected $view;

    /**
     * Create a new response instance.
     */
    public function __construct(
        callable|string $view,
    ) {
        $this->view = $view;
    }

    /**
     * Create an HTTP response that represents the object.
     */
    public function toResponse(Request $request): Response
    {
        if (! is_callable($this->view) || is_string($this->view)) {
            return response()->view($this->view, ['request' => $request]);
        }

        return Router::toResponse($request, ($this->view)($request));
    }
}
