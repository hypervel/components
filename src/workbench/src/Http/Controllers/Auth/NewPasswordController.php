<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Controllers\Auth;

use Hypervel\Auth\Events\PasswordReset;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Contracts\Auth\CanResetPassword;
use Hypervel\Contracts\View\View;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Hash;
use Hypervel\Support\Facades\Password;
use Hypervel\Support\Str;
use Hypervel\Validation\Rules\Password as PasswordRule;
use Hypervel\Validation\ValidationException;
use Hypervel\Workbench\Http\Controllers\Controller;

class NewPasswordController extends Controller
{
    /**
     * Display the password reset view.
     */
    public function create(Request $request): View
    {
        return view('auth.reset-password', ['request' => $request]);
    }

    /**
     * Handle an incoming new password request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', PasswordRule::defaults()],
        ]);

        // Here we will attempt to reset the user's password. If it is successful we
        // will update the password on an actual user model and persist it to the
        // database. Otherwise we will parse the error and return the response.
        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (Authenticatable&CanResetPassword&Model $user) use ($request): void {
                $user->forceFill([
                    'password' => Hash::make($request->password), // @phpstan-ignore property.notFound
                    'remember_token' => Str::random(60),
                ])->save();

                if (Event::hasListeners(PasswordReset::class)) {
                    event(new PasswordReset($user));
                }
            }
        );

        // If the password was successfully reset, we will redirect the user back to
        // the application's home authenticated view. If there is an error we can
        // redirect them back to where they came from with their error message.
        return $status === Password::PASSWORD_RESET
                    ? redirect()->route('login')->with('status', __($status))
                    : back()->withInput($request->only('email'))
                        ->withErrors(['email' => __($status)]);
    }
}
