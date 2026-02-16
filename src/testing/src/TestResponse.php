<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use ArrayAccess;
use Carbon\Carbon;
use Closure;
use Hyperf\Testing\AssertableJsonString;
use Hyperf\Testing\Fluent\AssertableJson;
use Hypervel\Container\Container;
use Hypervel\Contracts\Http\ResponsePlusInterface;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Contracts\Support\MessageBag;
use Hypervel\Cookie\Cookie;
use Hypervel\Support\Arr;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Testing\Concerns\AssertsStatusCodes;
use Hypervel\Testing\Constraints\SeeInOrder;
use Hypervel\Testing\TestResponseAssert as PHPUnit;
use LogicException;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * @mixin \Hypervel\HttpServer\Response
 */
class TestResponse implements ArrayAccess
{
    use AssertsStatusCodes, Tappable, Macroable {
        __call as macroCall;
    }

    protected ?array $decoded = null;

    /**
     * The streamed content of the response.
     */
    protected ?string $streamedContent = null;

    public function __construct(protected ResponseInterface $response)
    {
        if (method_exists($response, 'getStreamedContent')) {
            /** @var \Hypervel\Foundation\Testing\Http\ServerResponse $response */
            $this->streamedContent = $response->getStreamedContent();
        }
    }

    /**
     * Handle dynamic calls into macros or pass missing methods to the base response.
     */
    public function __call(string $method, array $args): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $args);
        }

        return $this->response->{$method}(...$args);
    }

    /**
     * Dynamically access base response parameters.
     */
    public function __get(string $key): mixed
    {
        return $this->response->{$key};
    }

    /**
     * Proxy isset() checks to the underlying base response.
     */
    public function __isset(string $key): bool
    {
        return isset($this->response->{$key});
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
    public function assertValid(array|string|null $keys = null, string $responseKey = 'errors'): static
    {
        return $this->assertJsonMissingValidationErrors($keys, $responseKey);
    }

    /**
     * Assert that the response has the given validation errors.
     */
    public function assertInvalid(array|string|null $errors = null, string $responseKey = 'errors'): static
    {
        return $this->assertJsonValidationErrors($errors, $responseKey);
    }

    protected function session(): SessionContract
    {
        $container = Container::getInstance();
        if (! $container->has(SessionContract::class)) {
            throw new RuntimeException('Package `hypervel/session` is not installed.');
        }

        return $container->make(SessionContract::class);
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

    /**
     * Get the content of the response.
     */
    public function getContent(): string
    {
        return $this->response->getBody()->getContents();
    }

    /**
     * Assert that the given string matches the response content.
     */
    public function assertContent(string $value): static
    {
        PHPUnit::assertSame($value, $this->getContent());

        return $this;
    }

    /**
     * Assert that the given string matches the streamed response content.
     */
    public function assertStreamedContent(string $value): static
    {
        PHPUnit::assertSame($value, $this->streamedContent());

        return $this;
    }

    /**
     * Assert that the given string or array of strings are contained within the response.
     */
    public function assertSee(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(fn ($value) => htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true), $value) : $value;

        foreach ($values as $value) {
            PHPUnit::assertStringContainsString((string) $value, $this->getContent());
        }

        return $this;
    }

    /**
     * Assert that the given strings are contained in order within the response.
     */
    public function assertSeeInOrder(array $values, bool $escape = true): static
    {
        $values = $escape ? array_map(fn ($value) => htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true), $values) : $values;

        PHPUnit::assertThat($values, new SeeInOrder($this->getContent()));

        return $this;
    }

    /**
     * Assert that the given string or array of strings are contained within the response text.
     */
    public function assertSeeText(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(fn ($value) => htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true), $value) : $value;

        $content = strip_tags($this->getContent());

        foreach ($values as $value) {
            PHPUnit::assertStringContainsString((string) $value, $content);
        }

        return $this;
    }

    /**
     * Assert that the given strings are contained in order within the response text.
     */
    public function assertSeeTextInOrder(array $values, bool $escape = true): static
    {
        $values = $escape ? array_map(fn ($value) => htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true), $values) : $values;

        PHPUnit::assertThat($values, new SeeInOrder(strip_tags($this->getContent())));

        return $this;
    }

    /**
     * Assert that the given string or array of strings are not contained within the response.
     */
    public function assertDontSee(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(fn ($value) => htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true), $value) : $value;

        foreach ($values as $value) {
            PHPUnit::assertStringNotContainsString((string) $value, $this->getContent());
        }

        return $this;
    }

    /**
     * Assert that the given string or array of strings are not contained within the response text.
     */
    public function assertDontSeeText(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(fn ($value) => htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', true), $value) : $value;

        $content = strip_tags($this->getContent());

        foreach ($values as $value) {
            PHPUnit::assertStringNotContainsString((string) $value, $content);
        }

        return $this;
    }

    /**
     * Assert that the response is a superset of the given JSON.
     *
     * @param array|callable $value
     */
    public function assertJson($value, bool $strict = false): static
    {
        $json = $this->decodeResponseJson();

        if (is_array($value)) {
            $json->assertSubset($value, $strict);
        } else {
            $assert = AssertableJson::fromAssertableJsonString($json);

            $value($assert);

            if (Arr::isAssoc($assert->toArray())) {
                $assert->interacted();
            }
        }

        return $this;
    }

    /**
     * Assert that the expected value and type exists at the given path in the response.
     */
    public function assertJsonPath(string $path, mixed $expect): static
    {
        $this->decodeResponseJson()->assertPath($path, $expect);

        return $this;
    }

    /**
     * Assert that the response has the exact given JSON.
     */
    public function assertExactJson(array $data): static
    {
        $this->decodeResponseJson()->assertExact($data);

        return $this;
    }

    /**
     * Assert that the response has the similar JSON as given.
     */
    public function assertSimilarJson(array $data): static
    {
        $this->decodeResponseJson()->assertSimilar($data);

        return $this;
    }

    /**
     * Assert that the response contains the given JSON fragment.
     */
    public function assertJsonFragment(array $data): static
    {
        $this->decodeResponseJson()->assertFragment($data);

        return $this;
    }

    /**
     * Assert that the response does not contain the given JSON fragment.
     */
    public function assertJsonMissing(array $data, bool $exact = false): static
    {
        $this->decodeResponseJson()->assertMissing($data, $exact);

        return $this;
    }

    /**
     * Assert that the response does not contain the exact JSON fragment.
     */
    public function assertJsonMissingExact(array $data): static
    {
        $this->decodeResponseJson()->assertMissingExact($data);

        return $this;
    }

    /**
     * Assert that the response does not contain the given path.
     */
    public function assertJsonMissingPath(string $path): static
    {
        $this->decodeResponseJson()->assertMissingPath($path);

        return $this;
    }

    /**
     * Assert that the response has a given JSON structure.
     */
    public function assertJsonStructure(?array $structure = null, mixed $responseData = null): static
    {
        $this->decodeResponseJson()->assertStructure($structure, $responseData);

        return $this;
    }

    /**
     * Assert that the response JSON has the expected count of items at the given key.
     */
    public function assertJsonCount(int $count, ?string $key = null): static
    {
        $this->decodeResponseJson()->assertCount($count, $key);

        return $this;
    }

    /**
     * Assert that the response has the given JSON validation errors.
     */
    public function assertJsonValidationErrors(array|string $errors, string $responseKey = 'errors'): static
    {
        $errors = Arr::wrap($errors);

        PHPUnit::assertNotEmpty($errors, 'No validation errors were provided.');

        $jsonErrors = Arr::get($this->json(), $responseKey) ?? [];

        $errorMessage = $jsonErrors
                ? 'Response has the following JSON validation errors:'
                        . PHP_EOL . PHP_EOL . json_encode($jsonErrors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
                : 'Response does not have JSON validation errors.';

        foreach ($errors as $key => $value) {
            if (is_int($key)) {
                $this->assertJsonValidationErrorFor($value, $responseKey);

                continue;
            }

            $this->assertJsonValidationErrorFor($key, $responseKey);

            foreach (Arr::wrap($value) as $expectedMessage) {
                $errorMissing = true;

                foreach (Arr::wrap($jsonErrors[$key]) as $jsonErrorMessage) {
                    if (Str::contains($jsonErrorMessage, $expectedMessage)) {
                        $errorMissing = false;

                        break;
                    }
                }
            }

            if ($errorMissing) { /* @phpstan-ignore-line */
                PHPUnit::fail(
                    "Failed to find a validation error in the response for key and message: '{$key}' => '{$expectedMessage}'" . PHP_EOL . PHP_EOL . $errorMessage  /* @phpstan-ignore-line */
                );
            }
        }

        return $this;
    }

    /**
     * Assert the response has any JSON validation errors for the given key.
     */
    public function assertJsonValidationErrorFor(string $key, string $responseKey = 'errors'): static
    {
        $jsonErrors = Arr::get($this->json(), $responseKey) ?? [];

        $errorMessage = $jsonErrors
            ? 'Response has the following JSON validation errors:'
            . PHP_EOL . PHP_EOL . json_encode($jsonErrors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
            : 'Response does not have JSON validation errors.';

        PHPUnit::assertArrayHasKey(
            $key,
            $jsonErrors,
            "Failed to find a validation error in the response for key: '{$key}'" . PHP_EOL . PHP_EOL . $errorMessage
        );

        return $this;
    }

    /**
     * Assert that the response has no JSON validation errors for the given keys.
     */
    public function assertJsonMissingValidationErrors(array|string|null $keys = null, string $responseKey = 'errors'): static
    {
        if ($this->getContent() === '') {
            PHPUnit::assertTrue(true);

            return $this;
        }

        $json = $this->json();

        if (! Arr::has($json, $responseKey)) {
            PHPUnit::assertTrue(true);

            return $this;
        }

        $errors = Arr::get($json, $responseKey, []);

        if (is_null($keys) && count($errors) > 0) {
            PHPUnit::fail(
                'Response has unexpected validation errors: ' . PHP_EOL . PHP_EOL
                . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        foreach (Arr::wrap($keys) as $key) {
            PHPUnit::assertFalse(
                isset($errors[$key]),
                "Found unexpected validation error for key: '{$key}'"
            );
        }

        return $this;
    }

    /**
     * Assert that the given key is a JSON array.
     */
    public function assertJsonIsArray(?string $key = null): static
    {
        $data = $this->json($key);

        $encodedData = json_encode($data);

        PHPUnit::assertTrue(
            is_array($data)
            && str_starts_with($encodedData, '[')
            && str_ends_with($encodedData, ']')
        );

        return $this;
    }

    /**
     * Assert that the given key is a JSON object.
     */
    public function assertJsonIsObject(?string $key = null): static
    {
        $data = $this->json($key);

        $encodedData = json_encode($data);

        PHPUnit::assertTrue(
            is_array($data)
            && str_starts_with($encodedData, '{')
            && str_ends_with($encodedData, '}')
        );

        return $this;
    }

    /**
     * Validate and return the decoded response JSON.
     *
     * @throws Throwable
     */
    public function decodeResponseJson(): AssertableJsonString
    {
        $testJson = new AssertableJsonString($this->getContent());

        $decodedResponse = $testJson->json();

        if (is_null($decodedResponse) || $decodedResponse === false) {
            $exception = $this->exception ?? null;

            $exception && throw $exception;

            PHPUnit::fail('Invalid JSON was returned from the route.');
        }

        return $testJson;
    }

    /**
     * Get the JSON decoded body of the response as an array or scalar value.
     */
    public function json(?string $key = null): mixed
    {
        return $this->decodeResponseJson()->json($key);
    }

    /**
     * Get the JSON decoded body of the response as a collection.
     */
    public function collect(?string $key = null): Collection
    {
        return Collection::make($this->json($key));
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->json()[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->json()[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Response data may not be mutated using array access.');
    }

    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Response data may not be mutated using array access.');
    }

    /**
     * Create a TestResponse from a base response.
     */
    public static function fromBaseResponse(ResponsePlusInterface $response): static
    {
        return new static($response);
    }

    /**
     * Assert that the response has a successful status code.
     */
    public function assertSuccessful(): static
    {
        PHPUnit::assertTrue(
            $this->isSuccessful(),
            $this->statusMessageWithDetails('>=200, <300', $this->getStatusCode())
        );

        return $this;
    }

    /**
     * Assert that the response has the given status code.
     */
    public function assertStatus(int $status): static
    {
        $message = $this->statusMessageWithDetails($status, $actual = $this->getStatusCode());

        PHPUnit::assertSame($actual, $status, $message);

        return $this;
    }

    /**
     * Determine if the response was successful.
     */
    public function isSuccessful(): bool
    {
        return $this->getStatusCode() >= 200 && $this->getStatusCode() < 300;
    }

    /**
     * Determine if there was a server error.
     */
    public function isServerError(): bool
    {
        return $this->getStatusCode() >= 500 && $this->getStatusCode() < 600;
    }

    /**
     * Assert that the response is a server error.
     */
    public function assertServerError(): static
    {
        PHPUnit::assertTrue(
            $this->isServerError(),
            $this->statusMessageWithDetails('>=500, < 600', $this->getStatusCode())
        );

        return $this;
    }

    /**
     * Get the response status code.
     */
    public function getStatusCode(): int
    {
        return $this->response->getStatusCode();
    }

    /**
     * Get the streamed content from the response.
     */
    public function streamedContent(): string
    {
        if (! is_null($this->streamedContent)) {
            return $this->streamedContent;
        }

        if (! $this->response instanceof StreamedResponse) {
            PHPUnit::fail('The response is not a streamed response.');
        }

        ob_start(function (string $buffer): string {
            $this->streamedContent .= $buffer;

            return '';
        });

        $this->sendContent();

        ob_end_clean();

        return (string) $this->streamedContent;
    }

    /**
     * Send the content for the current web response.
     */
    public function sendContent(): static
    {
        echo $this->streamedContent;

        return $this;
    }

    /**
     * Get an assertion message for a status assertion containing extra details when available.
     */
    protected function statusMessageWithDetails(int|string $expected, int|string $actual): string
    {
        return "Expected response status code [{$expected}] but received {$actual}.";
    }
}
