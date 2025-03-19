<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Testing\Http;

use Carbon\Carbon;
use Closure;
use Hyperf\Collection\Arr;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\MessageBag;
use Hyperf\Testing\Http\TestResponse as HyperfTestResponse;
use Hyperf\ViewEngine\ViewErrorBag;
use Hypervel\Cookie\Cookie;
use Hypervel\Foundation\Testing\TestResponseAssert as PHPUnit;
use Hypervel\Session\Contracts\Session as SessionContract;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;

class TestResponse extends HyperfTestResponse
{
    public function __construct(protected ResponseInterface $response)
    {
        if (method_exists($response, 'getStreamedContent')) {
            /** @var \Hypervel\Foundation\Testing\Http\ServerResponse $response */
            $this->streamedContent = $response->getStreamedContent();
        }
    }

    /**
     * Asserts that the response contains the given header and equals the optional value.
     */
    public function assertHeader(string $headerName, mixed $value = null): static
    {
        PHPUnit::assertTrue(
            $this->hasHeader($headerName),
            "Header [{$headerName}] not present on response."
        );

        $actual = $this->getHeader($headerName)[0] ?? null;

        if (! is_null($value)) {
            PHPUnit::assertEquals(
                $value,
                $actual,
                "Header [{$headerName}] was found, but value [{$actual}] does not match [{$value}]."
            );
        }

        return $this;
    }

    /**
     * Asserts that the response does not contain the given header.
     */
    public function assertHeaderMissing(string $headerName): static
    {
        PHPUnit::assertFalse(
            $this->hasHeader($headerName),
            "Unexpected header [{$headerName}] is present on response."
        );

        return $this;
    }

    /**
     * Assert that the response offers a file download.
     */
    public function assertDownload(?string $filename = null): static
    {
        $contentDisposition = explode(';', $this->getHeader('content-disposition')[0] ?? '');

        if (trim($contentDisposition[0]) !== 'attachment') {
            PHPUnit::fail(
                'Response does not offer a file download.' . PHP_EOL
                . 'Disposition [' . trim($contentDisposition[0]) . '] found in header, [attachment] expected.'
            );
        }

        if (! is_null($filename)) {
            if (isset($contentDisposition[1])
                && trim(explode('=', $contentDisposition[1])[0]) !== 'filename') {
                PHPUnit::fail(
                    'Unsupported Content-Disposition header provided.' . PHP_EOL
                    . 'Disposition [' . trim(explode('=', $contentDisposition[1])[0]) . '] found in header, [filename] expected.'
                );
            }

            $message = "Expected file [{$filename}] is not present in Content-Disposition header.";

            if (! isset($contentDisposition[1])) {
                PHPUnit::fail($message);
            } else {
                PHPUnit::assertSame(
                    $filename,
                    isset(explode('=', $contentDisposition[1])[1])
                        ? trim(explode('=', $contentDisposition[1])[1], " \"'")
                        : '',
                    $message
                );

                return $this;
            }
        } else {
            PHPUnit::assertTrue(true);

            return $this;
        }
    }

    /**
     * Asserts that the response contains the given cookie and equals the optional value.
     */
    public function assertPlainCookie(string $cookieName, mixed $value = null): static
    {
        return $this->assertCookie($cookieName, $value);
    }

    /**
     * Asserts that the response contains the given cookie and equals the optional value.
     */
    public function assertCookie(string $cookieName, mixed $value = null): static
    {
        PHPUnit::assertNotNull(
            $cookie = $this->getCookie($cookieName),
            "Cookie [{$cookieName}] not present on response."
        );

        if (! $cookie || is_null($value)) {
            return $this;
        }

        $cookieValue = $cookie->getValue();

        PHPUnit::assertEquals(
            $value,
            $cookieValue,
            "Cookie [{$cookieName}] was found, but value [{$cookieValue}] does not match [{$value}]."
        );

        return $this;
    }

    /**
     * Asserts that the response contains the given cookie and is expired.
     */
    public function assertCookieExpired(string $cookieName): static
    {
        PHPUnit::assertNotNull(
            $cookie = $this->getCookie($cookieName),
            "Cookie [{$cookieName}] not present on response."
        );

        $expiresAt = Carbon::createFromTimestamp($cookie->getExpiresTime());

        PHPUnit::assertTrue(
            $cookie->getExpiresTime() !== 0 && $expiresAt->lessThan(Carbon::now()),
            "Cookie [{$cookieName}] is not expired, it expires at [{$expiresAt}]."
        );

        return $this;
    }

    /**
     * Asserts that the response contains the given cookie and is not expired.
     */
    public function assertCookieNotExpired(string $cookieName): static
    {
        PHPUnit::assertNotNull(
            $cookie = $this->getCookie($cookieName),
            "Cookie [{$cookieName}] not present on response."
        );

        $expiresAt = Carbon::createFromTimestamp($cookie->getExpiresTime());

        PHPUnit::assertTrue(
            $cookie->getExpiresTime() === 0 || $expiresAt->greaterThan(Carbon::now()),
            "Cookie [{$cookieName}] is expired, it expired at [{$expiresAt}]."
        );

        return $this;
    }

    /**
     * Asserts that the response does not contain the given cookie.
     */
    public function assertCookieMissing(string $cookieName): static
    {
        PHPUnit::assertNull(
            $this->getCookie($cookieName),
            "Cookie [{$cookieName}] is present on response."
        );

        return $this;
    }

    /**
     * Get the given cookie from the response.
     */
    public function getCookie(string $cookieName): ?Cookie
    {
        /* @phpstan-ignore-next-line */
        foreach (Arr::flatten($this->getCookies()) as $cookie) {
            if ($cookie->getName() === $cookieName) {
                return $cookie;
            }
        }

        return null;
    }

    /**
     * Assert that the given keys do not have validation errors.
     */
    public function assertValid(null|array|string $keys = null, string $responseKey = 'errors'): static
    {
        return $this->assertJsonMissingValidationErrors($keys, $responseKey);
    }

    /**
     * Assert that the response has the given validation errors.
     */
    public function assertInvalid(null|array|string $errors = null, string $responseKey = 'errors'): static
    {
        return $this->assertJsonValidationErrors($errors, $responseKey);
    }

    protected function session(): SessionContract
    {
        $container = ApplicationContext::getContainer();
        if (! $container->has(SessionContract::class)) {
            throw new RuntimeException('Package `hypervel/session` is not installed.');
        }

        return $container->get(SessionContract::class);
    }

    /**
     * Assert that the session has a given value.
     */
    public function assertSessionHas(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            return $this->assertSessionHasAll($key);
        }

        if (is_null($value)) {
            PHPUnit::assertTrue(
                $this->session()->has($key),
                "Session is missing expected key [{$key}]."
            );
        } elseif ($value instanceof Closure) {
            PHPUnit::assertTrue($value($this->session()->get($key)));
        } else {
            PHPUnit::assertEquals($value, $this->session()->get($key));
        }

        return $this;
    }

    /**
     * Assert that the session has a given list of values.
     */
    public function assertSessionHasAll(array $bindings): static
    {
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $this->assertSessionHas($value);
            } else {
                $this->assertSessionHas($key, $value);
            }
        }

        return $this;
    }

    /**
     * Assert that the session has a given value in the flashed input array.
     */
    public function assertSessionHasInput(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                if (is_int($k)) {
                    $this->assertSessionHasInput($v);
                } else {
                    $this->assertSessionHasInput($k, $v);
                }
            }

            return $this;
        }

        if (is_null($value)) {
            PHPUnit::withResponse($this)->assertTrue(
                $this->session()->hasOldInput($key), /* @phpstan-ignore-line */
                "Session is missing expected key [{$key}]."
            );
        } elseif ($value instanceof Closure) {
            /* @phpstan-ignore-next-line */
            PHPUnit::withResponse($this)->assertTrue($value($this->session()->getOldInput($key)));
        } else {
            /* @phpstan-ignore-next-line */
            PHPUnit::withResponse($this)->assertEquals($value, $this->session()->getOldInput($key));
        }

        return $this;
    }

    /**
     * Assert that the session has the given errors.
     */
    public function assertSessionHasErrors(array|string $keys = [], mixed $format = null, string $errorBag = 'default'): static
    {
        $this->assertSessionHas('errors');

        $keys = (array) $keys;

        $errors = $this->session()->get('errors')->getBag($errorBag);

        foreach ($keys as $key => $value) {
            if (is_int($key)) {
                PHPUnit::withResponse($this)->assertTrue($errors->has($value), "Session missing error: {$value}");
            } else {
                PHPUnit::withResponse($this)->assertContains(is_bool($value) ? (string) $value : $value, $errors->get($key, $format));
            }
        }

        return $this;
    }

    /**
     * Assert that the session has the given errors.
     */
    public function assertSessionHasErrorsIn(string $errorBag, array $keys = [], mixed $format = null): static
    {
        return $this->assertSessionHasErrors($keys, $format, $errorBag);
    }

    /**
     * Assert that the session has no errors.
     */
    public function assertSessionHasNoErrors(): static
    {
        $hasErrors = $this->session()->has('errors');

        PHPUnit::withResponse($this)->assertFalse(
            $hasErrors,
            'Session has unexpected errors: ' . PHP_EOL . PHP_EOL
                . json_encode((function () use ($hasErrors) {
                    $errors = [];

                    $sessionErrors = $this->session()->get('errors');

                    if ($hasErrors && is_a($sessionErrors, ViewErrorBag::class)) {
                        foreach ($sessionErrors->getBags() as $bag => $messages) {
                            if (is_a($messages, MessageBag::class)) {
                                $errors[$bag] = $messages->all();
                            }
                        }
                    }

                    return $errors;
                })(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );

        return $this;
    }

    /**
     * Assert that the session is missing the given errors.
     */
    public function assertSessionDoesntHaveErrors(array|string $keys = [], ?string $format = null, string $errorBag = 'default'): static
    {
        $keys = (array) $keys;

        if (empty($keys)) {
            return $this->assertSessionHasNoErrors();
        }

        if (is_null($this->session()->get('errors'))) {
            PHPUnit::withResponse($this)->assertTrue(true);

            return $this;
        }

        $errors = $this->session()->get('errors')->getBag($errorBag);

        foreach ($keys as $key => $value) {
            if (is_int($key)) {
                PHPUnit::withResponse($this)->assertFalse($errors->has($value), "Session has unexpected error: {$value}");
            } else {
                PHPUnit::withResponse($this)->assertNotContains($value, $errors->get($key, $format));
            }
        }

        return $this;
    }

    /**
     * Assert that the session does not have a given key.
     */
    public function assertSessionMissing(array|string $key): static
    {
        if (is_array($key)) {
            foreach ($key as $value) {
                $this->assertSessionMissing($value);
            }
        } else {
            PHPUnit::assertFalse(
                $this->session()->has($key),
                "Session has unexpected key [{$key}]."
            );
        }

        return $this;
    }

    /**
     * Dump the content from the response and end the script.
     *
     * @return never
     */
    public function dd(): void
    {
        $this->dump();

        exit(1);
    }

    /**
     * Dump the content from the response.
     */
    public function dump(): static
    {
        $content = $this->getContent();

        $json = json_decode($content);

        if (json_last_error() === JSON_ERROR_NONE) {
            $content = $json;
        }

        dump($content);

        return $this;
    }

    /**
     * Dump the headers from the response.
     */
    public function dumpHeaders(): static
    {
        dump($this->getHeaders());

        return $this;
    }

    /**
     * Dump the session from the response.
     */
    public function dumpSession(): static
    {
        dump($this->session()->all());

        return $this;
    }
}
