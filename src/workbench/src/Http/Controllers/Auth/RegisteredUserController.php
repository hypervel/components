<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Controllers\Auth;

use Hypervel\Auth\Events\Registered;
use Hypervel\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Hypervel\Contracts\View\View;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\User;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Hash;
use Hypervel\Validation\Rules\Password;
use Hypervel\Validation\ValidationException;
use Hypervel\Workbench\Http\Controllers\Controller;
use Hypervel\Workbench\Workbench;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        // Upstream passes the model to its php -S child through TESTBENCH_USER_MODEL.
        // Hypervel serves in process, and validates against the model it creates.
        /** @var class-string<AuthenticatableContract&Model> $userModel */
        $userModel = Workbench::applicationUserModel() ?? User::class;

        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . $userModel],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user = $userModel::forceCreate([
            'name' => $request->name, // @phpstan-ignore property.notFound
            'email' => $request->email, // @phpstan-ignore property.notFound
            'password' => Hash::make($request->password), // @phpstan-ignore property.notFound
        ]);

        if (Event::hasListeners(Registered::class)) {
            event(new Registered($user));
        }

        Auth::login($user);

        return redirect($this->redirectToAfterLoggedIn());
    }
}
