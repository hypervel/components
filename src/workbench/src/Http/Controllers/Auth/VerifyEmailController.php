<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Controllers\Auth;

use Hypervel\Auth\Events\Verified;
use Hypervel\Contracts\Auth\MustVerifyEmail;
use Hypervel\Foundation\Auth\EmailVerificationRequest;
use Hypervel\Http\RedirectResponse;
use Hypervel\Support\Facades\Event;
use Hypervel\Workbench\Http\Controllers\Controller;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false) . '?verified=1');
        }

        if ($request->user()->markEmailAsVerified() && Event::hasListeners(Verified::class)) {
            /** @var MustVerifyEmail $user */
            $user = $request->user();

            event(new Verified($user));
        }

        return redirect()->intended(route('dashboard', absolute: false) . '?verified=1');
    }
}
