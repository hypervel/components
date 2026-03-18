<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Exception;
use Hypervel\Http\Request;

class AuthenticationException extends Exception
{
    /**
     * All of the guards that were checked.
     */
    protected array $guards;

    /**
     * The path the user should be redirected to.
     */
    protected ?string $redirectTo;

    /**
     * The callback that should be used to generate the authentication redirect path.
     *
     * @var null|callable
     */
    protected static $redirectToCallback;

    /**
     * Create a new authentication exception.
     */
    public function __construct(string $message = 'Unauthenticated.', array $guards = [], ?string $redirectTo = null)
    {
        parent::__construct($message);

        $this->guards = $guards;
        $this->redirectTo = $redirectTo;
    }

    /**
     * Get the guards that were checked.
     */
    public function guards(): array
    {
        return $this->guards;
    }

    /**
     * Get the path the user should be redirected to.
     */
    public function redirectTo(Request $request): ?string
    {
        if ($this->redirectTo) {
            return $this->redirectTo;
        }

        if (static::$redirectToCallback) {
            return call_user_func(static::$redirectToCallback, $request);
        }

        return null;
    }

    /**
     * Specify the callback that should be used to generate the redirect path.
     */
    public static function redirectUsing(callable $redirectToCallback): void
    {
        static::$redirectToCallback = $redirectToCallback;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        static::$redirectToCallback = null;
    }
}
