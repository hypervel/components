<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Fixtures;

use Hypervel\Context\CoroutineContext;
use Hypervel\Testing\TestResponse;

trait MakesBrowserRequests
{
    /**
     * Send the next request the way a browser would, with only the previous response's session cookie.
     *
     * Test requests otherwise inherit the previous request's started session and
     * authenticated users, which hides state that only survives through the session.
     */
    protected function withSessionCookieFrom(TestResponse $previous): static
    {
        foreach ([...$this->sessionContextKeys(), ...$this->authenticationContextKeys()] as $key) {
            CoroutineContext::forget($key);
        }

        $cookie = $previous->getCookie(config('session.cookie'), decrypt: false);

        $this->assertNotNull($cookie);

        return $this->withUnencryptedCookie($cookie->getName(), (string) $cookie->getValue());
    }
}
