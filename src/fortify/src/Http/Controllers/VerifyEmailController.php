<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Auth\Events\Verified;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Contracts\VerifyEmailResponse;
use Hypervel\Fortify\Http\Requests\VerifyEmailRequest;
use Hypervel\Routing\Controller;

class VerifyEmailController extends Controller
{
    use DispatchesEvents;

    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(VerifyEmailRequest $request): VerifyEmailResponse
    {
        /** @var MustVerifyEmail $user */
        $user = $request->user();

        if ($user->hasVerifiedEmail()) {
            return app(VerifyEmailResponse::class);
        }

        if ($user->markEmailAsVerified()) {
            $this->dispatchIfListening(
                Verified::class,
                fn (): Verified => new Verified($user),
            );
        }

        return app(VerifyEmailResponse::class);
    }
}
