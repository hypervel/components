<?php

declare(strict_types=1);

namespace Hypervel\Fortify\Http\Controllers;

use Hypervel\Auth\Events\Registered;
use Hypervel\Contracts\Config\Repository as Config;
use Hypervel\Contracts\Container\Container;
use Hypervel\Fortify\Concerns\DispatchesEvents;
use Hypervel\Fortify\Contracts\CreatesNewUsers;
use Hypervel\Fortify\Contracts\RegisterResponse;
use Hypervel\Fortify\Contracts\RegisterViewResponse;
use Hypervel\Fortify\Fortify;
use Hypervel\Http\Request;
use Hypervel\Routing\Controller;
use Hypervel\Support\Str;

class RegisteredUserController extends Controller
{
    use DispatchesEvents;

    public function __construct(
        private readonly Container $container,
        private readonly Config $config,
    ) {
    }

    /**
     * Show the registration view.
     */
    public function create(Request $request): RegisterViewResponse
    {
        return $this->container->make(RegisterViewResponse::class);
    }

    /**
     * Create a new registered user.
     */
    public function store(Request $request, CreatesNewUsers $creator): RegisterResponse
    {
        if ($this->config->boolean('fortify.lowercase_usernames') && $request->has(Fortify::username())) {
            $request->merge([
                Fortify::username() => Str::lower((string) $request->{Fortify::username()}),
            ]);
        }

        $user = $creator->create($request->all());

        $this->dispatchIfListening(
            Registered::class,
            static fn (): Registered => new Registered($user),
        );

        Fortify::guard()->login($user, $request->boolean('remember'));

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return $this->container->make(RegisterResponse::class);
    }
}
