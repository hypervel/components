<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Controllers\Auth;

use Hypervel\Contracts\View\View;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;
use Hypervel\Workbench\Http\Controllers\Controller;
use Hypervel\Workbench\Http\Requests\Auth\LoginRequest;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(): View
    {
        return view('auth.login');
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        $request->session()->regenerate();

        return redirect()->intended($this->redirectToAfterLoggedIn());
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        /* @phpstan-ignore method.notFound */
        Auth::guard()->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
