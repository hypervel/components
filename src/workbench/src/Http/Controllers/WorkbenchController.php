<?php

declare(strict_types=1);

namespace Hypervel\Workbench\Http\Controllers;

use Hypervel\Contracts\Auth\UserProvider;
use Hypervel\Http\RedirectResponse;
use Hypervel\Http\Request;
use Hypervel\Support\Facades\Auth;
use Hypervel\Support\Facades\Session;
use Hypervel\Support\Str;
use Hypervel\Workbench\Workbench;

class WorkbenchController extends Controller
{
    /**
     * Start page.
     */
    public function start(Request $request): RedirectResponse
    {
        $workbench = Workbench::config();

        if (\is_null($workbench['user'])) {
            return $this->logout($workbench['guard']);
        }

        $request->session()->flush();

        return $this->login((string) $workbench['user'], $workbench['guard']);
    }

    /**
     * Retrieve the authenticated user identifier and class name.
     *
     * @return array<string, mixed>
     *
     * @phpstan-return array{id?: string|int, className?: string}
     */
    public function user(?string $guard = null): array
    {
        $user = Auth::guard($guard)->user();

        if (! $user) {
            return [];
        }

        return [
            'id' => $user->getAuthIdentifier(),
            'className' => $user::class,
        ];
    }

    /**
     * Login using the given user ID / email.
     */
    public function login(string $userId, ?string $guard = null): RedirectResponse
    {
        $guard = $guard ?: config()->string('auth.defaults.guard');

        /** @var UserProvider $provider */
        $provider = Auth::guard($guard)->getProvider(); // @phpstan-ignore method.notFound

        $user = Str::contains($userId, '@')
            ? $provider->retrieveByCredentials(['email' => $userId])
            : $provider->retrieveById($userId);

        /* @phpstan-ignore method.notFound */
        Auth::guard($guard)->login($user);

        return redirect(Workbench::config('start'));
    }

    /**
     * Log the user out of the application.
     */
    public function logout(?string $guard = null): RedirectResponse
    {
        $guard = $guard ?: config()->string('auth.defaults.guard');

        /* @phpstan-ignore method.notFound */
        Auth::guard($guard)->logout();

        Session::forget('password_hash_' . $guard);

        return redirect(Workbench::config('start'));
    }
}
