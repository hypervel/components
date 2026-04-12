<?php

declare(strict_types=1);

namespace Hypervel\Socialite\Testing;

use Closure;
use Hypervel\Http\RedirectResponse;
use Hypervel\Socialite\Contracts\Provider;
use Hypervel\Socialite\Contracts\User as UserContract;
use Hypervel\Support\Traits\ForwardsCalls;

class FakeProvider implements Provider
{
    use ForwardsCalls;

    /**
     * The original provider instance.
     */
    protected ?Provider $provider = null;

    /**
     * Create a new fake provider instance.
     */
    public function __construct(
        protected string $driver,
        protected Closure $resolver,
        protected UserContract|Closure|null $user = null
    ) {
    }

    /**
     * Redirect the user to the authentication page for the provider.
     */
    public function redirect(): RedirectResponse
    {
        return new RedirectResponse('https://socialite.fake/' . $this->driver . '/authorize');
    }

    /**
     * Get the User instance for the authenticated user.
     */
    public function user(): UserContract
    {
        if ($this->user instanceof Closure) {
            return ($this->user)();
        }

        return $this->user ?? $this->provider()->user();
    }

    /**
     * Get the original provider instance.
     */
    public function provider(): Provider
    {
        if (isset($this->provider)) {
            return $this->provider;
        }

        return $this->provider = ($this->resolver)();
    }

    /**
     * Handle calls to methods that are not available on the fake provider.
     */
    public function __call(string $method, array $parameters): mixed
    {
        return $this->forwardDecoratedCallTo($this->provider(), $method, $parameters);
    }
}
