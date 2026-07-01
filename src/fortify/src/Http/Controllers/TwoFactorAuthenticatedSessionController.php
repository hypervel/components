<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Contracts\FailedTwoFactorLoginResponse;
use Hypervel\Fortify\Contracts\TwoFactorAuthenticationUser;
use Hypervel\Fortify\Contracts\TwoFactorChallengeViewResponse;
use Hypervel\Fortify\Contracts\TwoFactorLoginResponse;
use Hypervel\Fortify\Events\TwoFactorAuthenticationFailed;
use Hypervel\Fortify\Events\ValidTwoFactorAuthenticationCodeProvided;
use Hypervel\Fortify\Fortify;
use Hypervel\Fortify\Http\Requests\TwoFactorLoginRequest;
use Hypervel\Http\Exceptions\HttpResponseException;
use Hypervel\Routing\Controller;

class TwoFactorAuthenticatedSessionController extends Controller
{
    use DispatchesEvents;

    public function __construct(
        private readonly Container $container,
        private readonly Dispatcher $events,
    ) {
    }

    /**
     * Show the two factor authentication challenge view.
     */
    public function create(TwoFactorLoginRequest $request): TwoFactorChallengeViewResponse
    {
        if (! $request->hasChallengedUser()) {
            throw new HttpResponseException(redirect()->route('login'));
        }

        return $this->container->make(TwoFactorChallengeViewResponse::class);
    }

    /**
     * Attempt to authenticate a new session using the two factor authentication code.
     */
    public function store(TwoFactorLoginRequest $request): mixed
    {
        /** @var Authenticatable&Model&TwoFactorAuthenticationUser $user */
        $user = $request->challengedUser();

        if ($code = $request->validRecoveryCode()) {
            $user->replaceRecoveryCode($code);
        } elseif (! $request->hasValidCode()) {
            $this->dispatchIfListening(
                $this->events,
                TwoFactorAuthenticationFailed::class,
                static fn (): TwoFactorAuthenticationFailed => new TwoFactorAuthenticationFailed($user),
            );

            return $this->container->make(FailedTwoFactorLoginResponse::class)->toResponse($request);
        }

        $this->dispatchIfListening(
            $this->events,
            ValidTwoFactorAuthenticationCodeProvided::class,
            static fn (): ValidTwoFactorAuthenticationCodeProvided => new ValidTwoFactorAuthenticationCodeProvided($user),
        );

        Fortify::guard()->login($user, $request->remember());

        $request->session()->regenerate();

        return $this->container->make(TwoFactorLoginResponse::class);
    }
}
