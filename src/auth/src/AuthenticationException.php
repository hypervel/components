<?php

declare(strict_types=1);

namespace Hypervel\Auth;

use Exception;
use Psr\Http\Message\RequestInterface;

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
     * @var callable
     */
    protected static $redirectToCallback;

    /**
     * Create a new authentication exception.
     *
     * @param string $message
     */
    public function __construct($message = 'Unauthenticated.', array $guards = [], ?string $redirectTo = null)
    {
        parent::__construct($message);

        $this->guards = $guards;
        $this->redirectTo = $redirectTo;
    }

    /**
     * Get the guards that were checked.
     *
     * @return array
     */
    public function guards()
    {
        return $this->guards;
    }

    /**
     * Get the path the user should be redirected to.
     */
    public function redirectTo(RequestInterface $request): ?string
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
}
