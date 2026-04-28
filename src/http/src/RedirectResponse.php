<?php

declare(strict_types=1);

namespace Hypervel\Http;

use BadMethodCallException;
use Hypervel\Contracts\Support\MessageBag as MessageBagContract;
use Hypervel\Contracts\Support\MessageProvider;
use Hypervel\Session\Store as SessionStore;
use Hypervel\Support\MessageBag;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\ForwardsCalls;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Uri;
use Hypervel\Support\ViewErrorBag;
use Symfony\Component\HttpFoundation\File\UploadedFile as SymfonyUploadedFile;
use Symfony\Component\HttpFoundation\RedirectResponse as BaseRedirectResponse;

class RedirectResponse extends BaseRedirectResponse
{
    use ForwardsCalls, ResponseTrait, Macroable {
        Macroable::__call as macroCall;
    }

    /**
     * The request instance.
     */
    protected ?Request $request = null;

    /**
     * The session store instance.
     */
    protected ?SessionStore $session = null;

    /**
     * Flash a piece of data to the session.
     */
    public function with(string|array $key, mixed $value = null): static
    {
        $key = is_array($key) ? $key : [$key => $value];

        foreach ($key as $k => $v) {
            $this->session->flash($k, $v);
        }

        return $this;
    }

    /**
     * Add multiple cookies to the response.
     */
    public function withCookies(array $cookies): static
    {
        foreach ($cookies as $cookie) {
            $this->headers->setCookie($cookie);
        }

        return $this;
    }

    /**
     * Flash an array of input to the session.
     */
    public function withInput(?array $input = null): static
    {
        $this->session->flashInput($this->removeFilesFromInput(
            ! is_null($input) ? $input : $this->request->input()
        ));

        return $this;
    }

    /**
     * Remove all uploaded files from the given input array.
     */
    protected function removeFilesFromInput(array $input): array
    {
        foreach ($input as $key => $value) {
            if (is_array($value)) {
                $input[$key] = $this->removeFilesFromInput($value);
            }

            if ($value instanceof SymfonyUploadedFile) {
                unset($input[$key]);
            }
        }

        return $input;
    }

    /**
     * Flash only the specified input keys to the session.
     */
    public function onlyInput(): static
    {
        return $this->withInput($this->request->only(func_get_args()));
    }

    /**
     * Flash all input except the specified keys to the session.
     */
    public function exceptInput(): static
    {
        return $this->withInput($this->request->except(func_get_args()));
    }

    /**
     * Flash a container of errors to the session.
     */
    public function withErrors(MessageProvider|array|string $provider, string $key = 'default'): static
    {
        $value = $this->parseErrors($provider);

        $errors = $this->session->get('errors', new ViewErrorBag);

        if (! $errors instanceof ViewErrorBag) {
            $errors = new ViewErrorBag;
        }

        $this->session->flash(
            'errors',
            $errors->put($key, $value)
        );

        return $this;
    }

    /**
     * Parse the given errors into an appropriate value.
     */
    protected function parseErrors(MessageProvider|array|string $provider): MessageBagContract
    {
        if ($provider instanceof MessageProvider) {
            return $provider->getMessageBag();
        }

        return new MessageBag((array) $provider);
    }

    /**
     * Add a fragment identifier to the URL.
     */
    public function withFragment(string $fragment): static
    {
        return $this->withoutFragment()
            ->setTargetUrl($this->getTargetUrl() . '#' . Str::after($fragment, '#'));
    }

    /**
     * Remove any fragment identifier from the response URL.
     */
    public function withoutFragment(): static
    {
        return $this->setTargetUrl(Str::before($this->getTargetUrl(), '#'));
    }

    /**
     * Enforce that the redirect target must have the same host as the current request.
     */
    public function enforceSameOrigin(
        string $fallback,
        bool $validateScheme = true,
        bool $validatePort = true,
    ): static {
        $target = Uri::of($this->targetUrl);
        $current = Uri::of($this->request->getSchemeAndHttpHost());

        if ($target->host() !== $current->host()
            || ($validateScheme && $target->scheme() !== $current->scheme())
            || ($validatePort && $target->port() !== $current->port())) {
            $this->setTargetUrl($fallback);
        }

        return $this;
    }

    /**
     * Get the original response content.
     */
    public function getOriginalContent(): mixed
    {
        return null;
    }

    /**
     * Get the request instance.
     */
    public function getRequest(): ?Request
    {
        return $this->request;
    }

    /**
     * Set the request instance.
     */
    public function setRequest(Request $request): static
    {
        $this->request = $request;

        return $this;
    }

    /**
     * Get the session store instance.
     */
    public function getSession(): ?SessionStore
    {
        return $this->session;
    }

    /**
     * Set the session store instance.
     */
    public function setSession(SessionStore $session): static
    {
        $this->session = $session;

        return $this;
    }

    /**
     * Dynamically bind flash data in the session.
     *
     * @throws BadMethodCallException
     */
    public function __call(string $method, array $parameters): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $parameters);
        }

        if (str_starts_with($method, 'with')) {
            return $this->with(Str::snake(substr($method, 4)), $parameters[0]);
        }

        static::throwBadMethodCallException($method);
    }
}
