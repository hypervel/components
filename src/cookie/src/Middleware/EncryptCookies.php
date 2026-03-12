<?php

declare(strict_types=1);

namespace Hypervel\Cookie\Middleware;

use Closure;
use Hypervel\Contracts\Encryption\DecryptException;
use Hypervel\Contracts\Encryption\Encrypter as EncrypterContract;
use Hypervel\Cookie\CookieValuePrefix;
use Hypervel\Http\Request;
use Hypervel\Support\Arr;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

class EncryptCookies
{
    /**
     * The names of the cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected array $except = [];

    /**
     * The globally ignored cookies that should not be encrypted.
     *
     * @var array<int, string>
     */
    protected static array $neverEncrypt = [];

    /**
     * The cookies that should be encrypted (opt-in mode).
     *
     * When non-empty, only these cookies will be encrypted and
     * the $except and $neverEncrypt lists are ignored.
     *
     * @var array<int, string>
     */
    protected static array $onlyEncrypt = [];

    /**
     * Indicates if cookies should be serialized.
     */
    protected static bool $serialize = false;

    /**
     * Create a new middleware instance.
     */
    public function __construct(
        protected EncrypterContract $encrypter,
    ) {
    }

    /**
     * Disable encryption for the given cookie name(s).
     */
    public function disableFor(array|string $name): void
    {
        $this->except = array_merge($this->except, (array) $name);
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        return $this->encrypt($next($this->decrypt($request)));
    }

    /**
     * Decrypt the cookies on the request.
     */
    protected function decrypt(Request $request): Request
    {
        foreach ($request->cookies as $key => $cookie) {
            if ($this->isDisabled($key)) {
                continue;
            }

            try {
                $value = $this->decryptCookie($key, $cookie);

                $request->cookies->set($key, $this->validateValue($key, $value));
            } catch (DecryptException) {
                $request->cookies->set($key, null);
            }
        }

        return $request;
    }

    /**
     * Validate and remove the cookie value prefix from the value.
     *
     * @phpstan-return ($value is array ? array<string|null> : string|null)
     */
    protected function validateValue(string $key, array|string $value): array|string|null
    {
        return is_array($value)
            ? $this->validateArray($key, $value)
            : CookieValuePrefix::validate($key, $value, $this->encrypter->getAllKeys());
    }

    /**
     * Validate and remove the cookie value prefix from all values of an array.
     */
    protected function validateArray(string $key, array $value): array
    {
        $validated = [];

        foreach ($value as $index => $subValue) {
            $validated[$index] = $this->validateValue("{$key}[{$index}]", $subValue);
        }

        return $validated;
    }

    /**
     * Decrypt the given cookie and return the value.
     */
    protected function decryptCookie(string $name, array|string $cookie): array|string
    {
        return is_array($cookie)
            ? $this->decryptArray($cookie)
            : $this->encrypter->decrypt($cookie, static::serialized($name));
    }

    /**
     * Decrypt an array based cookie.
     */
    protected function decryptArray(array $cookie): array
    {
        $decrypted = [];

        foreach ($cookie as $key => $value) {
            if (is_string($value)) {
                $decrypted[$key] = $this->encrypter->decrypt($value, static::serialized($key));
            }

            if (is_array($value)) {
                $decrypted[$key] = $this->decryptArray($value);
            }
        }

        return $decrypted;
    }

    /**
     * Encrypt the cookies on an outgoing response.
     */
    protected function encrypt(Response $response): Response
    {
        foreach ($response->headers->getCookies() as $cookie) {
            if ($this->isDisabled($cookie->getName())) {
                continue;
            }

            $response->headers->setCookie($this->duplicate(
                $cookie,
                $this->encrypter->encrypt(
                    CookieValuePrefix::create($cookie->getName(), $this->encrypter->getKey()) . $cookie->getValue(),
                    static::serialized($cookie->getName())
                )
            ));
        }

        return $response;
    }

    /**
     * Duplicate a cookie with a new value.
     */
    protected function duplicate(Cookie $cookie, mixed $value): Cookie
    {
        return $cookie->withValue($value);
    }

    /**
     * Determine whether encryption has been disabled for the given cookie.
     */
    public function isDisabled(string $name): bool
    {
        if (static::$onlyEncrypt !== []) {
            return ! in_array($name, static::$onlyEncrypt);
        }

        return in_array($name, array_merge($this->except, static::$neverEncrypt));
    }

    /**
     * Indicate that the given cookies should never be encrypted.
     */
    public static function except(array|string $cookies): void
    {
        static::$neverEncrypt = array_values(array_unique(
            array_merge(static::$neverEncrypt, Arr::wrap($cookies))
        ));
    }

    /**
     * Indicate that only the given cookies should be encrypted.
     *
     * When set, all other cookies pass through unencrypted.
     * Takes precedence over except() and the $except property.
     */
    public static function only(array|string $cookies): void
    {
        static::$onlyEncrypt = array_values(array_unique(
            array_merge(static::$onlyEncrypt, Arr::wrap($cookies))
        ));
    }

    /**
     * Determine if the cookie contents should be serialized.
     */
    public static function serialized(string $name): bool
    {
        return static::$serialize;
    }

    /**
     * Flush the middleware's global state.
     */
    public static function flushState(): void
    {
        static::$neverEncrypt = [];
        static::$onlyEncrypt = [];

        static::$serialize = false;
    }
}
