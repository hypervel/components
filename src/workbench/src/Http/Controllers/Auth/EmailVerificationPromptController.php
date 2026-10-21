<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Controllers\Auth;

use Hypervel\Contracts\View\View;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Workbench\Http\Controllers\Controller;

class EmailVerificationPromptController extends Controller
{
    /**
     * Display the email verification prompt.
     */
    public function __invoke(Request $request): RedirectResponse|View
    {
        return $request->user()->hasVerifiedEmail()
                    ? redirect()->intended(route('dashboard', absolute: false))
                    : view('auth.verify-email');
    }
}
