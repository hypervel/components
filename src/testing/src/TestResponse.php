<?php

declare(strict_types=1);

namespace Hypervel\Testing;

use ArrayAccess;
use BackedEnum;
use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Session\Session as SessionContract;
use Hypervel\Contracts\Support\MessageBag;
use Hypervel\Contracts\View\View;
use Hypervel\Cookie\CookieValuePrefix;
use Hypervel\Database\Eloquent\Collection as EloquentCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Http\Response as HypervelResponse;
use Hypervel\Support\Arr;
use Hypervel\Support\Carbon;
use Hypervel\Support\Collection;
use Hypervel\Support\Str;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Dumpable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Support\Traits\Tappable;
use Hypervel\Support\ViewErrorBag;
use Hypervel\Testing\Concerns\AssertsStatusCodes;
use Hypervel\Testing\Constraints\SeeInOrder;
use Hypervel\Testing\Fluent\AssertableJson;
use Hypervel\Testing\TestResponseAssert as PHPUnit;
use LogicException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\StreamedJsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * @template TResponse of \Symfony\Component\HttpFoundation\Response
 *
 * @mixin \Hypervel\Http\Response
 */
class TestResponse implements ArrayAccess
{
    use AssertsStatusCodes, Conditionable, Dumpable, Tappable, Macroable {
        __call as macroCall;
    }

    /**
     * The original request.
     */
    public ?Request $baseRequest;

    /**
     * The response to delegate to.
     *
     * @var TResponse
     */
    public $baseResponse;

    /**
     * The collection of logged exceptions for the request.
     */
    public Collection $exceptions;

    /**
     * The streamed content of the response.
     */
    protected ?string $streamedContent = null;

    /**
     * Create a new test response instance.
     *
     * @param TResponse $response
     */
    public function __construct($response, ?Request $request = null)
    {
        $this->baseResponse = $response;
        $this->baseRequest = $request;
        $this->exceptions = new Collection();
    }

    /**
     * Create a new TestResponse from another response.
     *
     * @template R of TResponse
     *
     * @param R $response
     * @return static<R>
     */
    public static function fromBaseResponse($response, ?Request $request = null): static
    {
        return new static($response, $request);
    }

    /**
     * Assert that the response has a successful status code.
     */
    public function assertSuccessful(): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            $this->isSuccessful(),
            $this->statusMessageWithDetails('>=200, <300', $this->getStatusCode())
        );

        return $this;
    }

    /**
     * Assert that the Precognition request was successful.
     */
    public function assertSuccessfulPrecognition(): static
    {
        $this->assertNoContent();

        PHPUnit::withResponse($this)->assertTrue(
            $this->headers->has('Precognition-Success'),
            'Header [Precognition-Success] not present on response.'
        );

        PHPUnit::withResponse($this)->assertSame(
            'true',
            $this->headers->get('Precognition-Success'),
            'The Precognition-Success header was found, but the value is not `true`.'
        );

        return $this;
    }

    /**
     * Assert that the response is a client error.
     */
    public function assertClientError(): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            $this->isClientError(),
            $this->statusMessageWithDetails('>=400, < 500', $this->getStatusCode())
        );

        return $this;
    }

    /**
     * Assert that the response is a server error.
     */
    public function assertServerError(): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            $this->isServerError(),
            $this->statusMessageWithDetails('>=500, < 600', $this->getStatusCode())
        );

        return $this;
    }

    /**
     * Assert that the response has the given status code.
     */
    public function assertStatus(int $status): static
    {
        $message = $this->statusMessageWithDetails($status, $actual = $this->getStatusCode());

        PHPUnit::withResponse($this)->assertSame($status, $actual, $message);

        return $this;
    }

    /**
     * Get an assertion message for a status assertion containing extra details when available.
     */
    protected function statusMessageWithDetails(int|string $expected, int|string $actual): string
    {
        return "Expected response status code [{$expected}] but received {$actual}.";
    }

    /**
     * Assert whether the response is redirecting to a given URI.
     */
    public function assertRedirect(?string $uri = null): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            $this->isRedirect(),
            $this->statusMessageWithDetails('201, 301, 302, 303, 307, 308', $this->getStatusCode()),
        );

        if (! is_null($uri)) {
            $this->assertLocation($uri);
        }

        return $this;
    }

    /**
     * Assert whether the response is redirecting to a URI that contains the given URI.
     */
    public function assertRedirectContains(string $uri): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            $this->isRedirect(),
            $this->statusMessageWithDetails('201, 301, 302, 303, 307, 308', $this->getStatusCode()),
        );

        PHPUnit::withResponse($this)->assertTrue(
            Str::contains($this->headers->get('Location'), $uri),
            'Redirect location [' . $this->headers->get('Location') . '] does not contain [' . $uri . '].'
        );

        return $this;
    }

    /**
     * Assert whether the response is redirecting back to the previous location.
     */
    public function assertRedirectBack(): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            $this->isRedirect(),
            $this->statusMessageWithDetails('201, 301, 302, 303, 307, 308', $this->getStatusCode()),
        );

        $this->assertLocation(app('url')->previous());

        return $this;
    }

    /**
     * Assert whether the response is redirecting back to the previous location with the given errors in the session.
     */
    public function assertRedirectBackWithErrors(array|string $keys = [], mixed $format = null, string $errorBag = 'default'): static
    {
        $this->assertRedirectBack();

        $this->assertSessionHasErrors($keys, $format, $errorBag);

        return $this;
    }

    /**
     * Assert whether the response is redirecting back to the previous location with no errors in the session.
     */
    public function assertRedirectBackWithoutErrors(): static
    {
        $this->assertRedirectBack();

        $this->assertSessionHasNoErrors();

        return $this;
    }

    /**
     * Assert whether the response is redirecting to a given route.
     */
    public function assertRedirectToRoute(BackedEnum|string $name, mixed $parameters = []): static
    {
        $uri = route($name, $parameters);

        PHPUnit::withResponse($this)->assertTrue(
            $this->isRedirect(),
            $this->statusMessageWithDetails('201, 301, 302, 303, 307, 308', $this->getStatusCode()),
        );

        $this->assertLocation($uri);

        return $this;
    }

    /**
     * Assert whether the response is redirecting to a given signed route.
     */
    public function assertRedirectToSignedRoute(BackedEnum|string|null $name = null, mixed $parameters = [], bool $absolute = true): static
    {
        if (! is_null($name)) {
            $uri = route($name, $parameters);
        }

        PHPUnit::withResponse($this)->assertTrue(
            $this->isRedirect(),
            $this->statusMessageWithDetails('201, 301, 302, 303, 307, 308', $this->getStatusCode()),
        );

        $request = Request::create($this->headers->get('Location'));

        PHPUnit::withResponse($this)->assertTrue(
            $request->hasValidSignature($absolute),
            'The response is not a redirect to a signed route.'
        );

        if (! is_null($name)) {
            $expectedUri = rtrim($request->fullUrlWithQuery([
                'signature' => null,
                'expires' => null,
            ]), '?');

            PHPUnit::withResponse($this)->assertEquals(
                app('url')->to($uri),
                $expectedUri
            );
        }

        return $this;
    }

    /**
     * Assert whether the response is redirecting to a given controller action.
     */
    public function assertRedirectToAction(array|string $name, array $parameters = []): static
    {
        $uri = action($name, $parameters);

        PHPUnit::withResponse($this)->assertTrue(
            $this->isRedirect(),
            $this->statusMessageWithDetails('201, 301, 302, 303, 307, 308', $this->getStatusCode()),
        );

        $this->assertLocation($uri);

        return $this;
    }

    /**
     * Asserts that the response contains the given header and equals the optional value.
     */
    public function assertHeader(string $headerName, mixed $value = null): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            $this->headers->has($headerName),
            "Header [{$headerName}] not present on response."
        );

        $actual = $this->headers->get($headerName);

        if (! is_null($value)) {
            PHPUnit::withResponse($this)->assertEqualsIgnoringCase(
                $value,
                $this->headers->get($headerName),
                "Header [{$headerName}] was found, but value [{$actual}] does not match [{$value}]."
            );
        }

        return $this;
    }

    /**
     * Asserts that the response contains the given header and that its value contains the given string.
     */
    public function assertHeaderContains(string $headerName, string $value): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            $this->headers->has($headerName),
            "Header [{$headerName}] not present on response."
        );

        $actual = $this->headers->get($headerName, '');

        PHPUnit::withResponse($this)->assertTrue(
            Str::contains($actual, $value),
            "Header [{$headerName}] was found, but [{$actual}] does not contain [{$value}]."
        );

        return $this;
    }

    /**
     * Asserts that the response does not contain the given header.
     */
    public function assertHeaderMissing(string $headerName): static
    {
        PHPUnit::withResponse($this)->assertFalse(
            $this->headers->has($headerName),
            "Unexpected header [{$headerName}] is present on response."
        );

        return $this;
    }

    /**
     * Assert that the current location header matches the given URI.
     */
    public function assertLocation(string $uri): static
    {
        PHPUnit::withResponse($this)->assertEquals(
            app('url')->to($uri),
            app('url')->to($this->headers->get('Location', ''))
        );

        return $this;
    }

    /**
     * Assert that the response offers a file download.
     */
    public function assertDownload(?string $filename = null): static
    {
        $contentDisposition = explode(';', $this->headers->get('content-disposition', ''));

        if (trim($contentDisposition[0]) !== 'attachment') {
            PHPUnit::withResponse($this)->fail(
                'Response does not offer a file download.' . PHP_EOL
                . 'Disposition [' . trim($contentDisposition[0]) . '] found in header, [attachment] expected.'
            );
        }

        if (! is_null($filename)) {
            if (isset($contentDisposition[1])
                && trim(explode('=', $contentDisposition[1])[0]) !== 'filename') {
                PHPUnit::withResponse($this)->fail(
                    'Unsupported Content-Disposition header provided.' . PHP_EOL
                    . 'Disposition [' . trim(explode('=', $contentDisposition[1])[0]) . '] found in header, [filename] expected.'
                );
            }

            $message = "Expected file [{$filename}] is not present in Content-Disposition header.";

            if (! isset($contentDisposition[1])) {
                PHPUnit::withResponse($this)->fail($message);
            } else {
                PHPUnit::withResponse($this)->assertSame(
                    $filename,
                    isset(explode('=', $contentDisposition[1])[1])
                        ? trim(explode('=', $contentDisposition[1])[1], " \"'")
                        : '',
                    $message
                );

                return $this;
            }
        } else {
            PHPUnit::withResponse($this)->assertTrue(true);

            return $this;
        }
    }

    /**
     * Asserts that the response contains the given cookie and equals the optional value.
     */
    public function assertPlainCookie(string $cookieName, mixed $value = null): static
    {
        $this->assertCookie($cookieName, $value, false);

        return $this;
    }

    /**
     * Asserts that the response contains the given cookie and equals the optional value.
     */
    public function assertCookie(string $cookieName, mixed $value = null, bool $encrypted = true, bool $unserialize = false): static
    {
        PHPUnit::withResponse($this)->assertNotNull(
            $cookie = $this->getCookie($cookieName, $encrypted && ! is_null($value), $unserialize),
            "Cookie [{$cookieName}] not present on response."
        );

        if (! $cookie || is_null($value)) {
            return $this;
        }

        $cookieValue = $cookie->getValue();

        PHPUnit::withResponse($this)->assertEquals(
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
        PHPUnit::withResponse($this)->assertNotNull(
            $cookie = $this->getCookie($cookieName, false),
            "Cookie [{$cookieName}] not present on response."
        );

        $expiresAt = Carbon::createFromTimestamp($cookie->getExpiresTime(), date_default_timezone_get());

        PHPUnit::withResponse($this)->assertTrue(
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
        PHPUnit::withResponse($this)->assertNotNull(
            $cookie = $this->getCookie($cookieName, false),
            "Cookie [{$cookieName}] not present on response."
        );

        $expiresAt = Carbon::createFromTimestamp($cookie->getExpiresTime(), date_default_timezone_get());

        PHPUnit::withResponse($this)->assertTrue(
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
        PHPUnit::withResponse($this)->assertNull(
            $this->getCookie($cookieName, false),
            "Cookie [{$cookieName}] is present on response."
        );

        return $this;
    }

    /**
     * Get the given cookie from the response.
     */
    public function getCookie(string $cookieName, bool $decrypt = true, bool $unserialize = false): ?Cookie
    {
        foreach ($this->headers->getCookies() as $cookie) {
            if ($cookie->getName() === $cookieName) {
                if (! $decrypt) {
                    return $cookie;
                }

                $decryptedValue = CookieValuePrefix::remove(
                    app('encrypter')->decrypt($cookie->getValue(), $unserialize)
                );

                return new Cookie(
                    $cookie->getName(),
                    $decryptedValue,
                    $cookie->getExpiresTime(),
                    $cookie->getPath(),
                    $cookie->getDomain(),
                    $cookie->isSecure(),
                    $cookie->isHttpOnly(),
                    $cookie->isRaw(),
                    $cookie->getSameSite(),
                    $cookie->isPartitioned()
                );
            }
        }

        return null;
    }

    /**
     * Assert that the given string matches the response content.
     */
    public function assertContent(string $value): static
    {
        PHPUnit::withResponse($this)->assertSame($value, $this->getContent());

        return $this;
    }

    /**
     * Assert that the response was streamed.
     */
    public function assertStreamed(): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            $this->isStreamedResponse(),
            'Expected the response to be streamed, but it wasn\'t.'
        );

        return $this;
    }

    /**
     * Assert that the response was not streamed.
     */
    public function assertNotStreamed(): static
    {
        PHPUnit::withResponse($this)->assertTrue(
            ! $this->isStreamedResponse(),
            'Response was unexpectedly streamed.'
        );

        return $this;
    }

    /**
     * Assert that the given string matches the streamed response content.
     */
    public function assertStreamedContent(string $value): static
    {
        PHPUnit::withResponse($this)->assertSame($value, $this->streamedContent());

        return $this;
    }

    /**
     * Assert that the given array matches the streamed JSON response content.
     */
    public function assertStreamedJsonContent(array $value): static
    {
        return $this->assertStreamedContent(json_encode($value, JSON_THROW_ON_ERROR));
    }

    /**
     * Assert that the given string or array of strings are contained within the response.
     */
    public function assertSee(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(e(...), $value) : $value;

        foreach ($values as $value) {
            PHPUnit::withResponse($this)->assertStringContainsString((string) $value, $this->getContent());
        }

        return $this;
    }

    /**
     * Assert that the given HTML string or array of HTML strings are contained within the response.
     */
    public function assertSeeHtml(array|string $value): static
    {
        return $this->assertSee($value, false);
    }

    /**
     * Assert that the given strings are contained in order within the response.
     */
    public function assertSeeInOrder(array $values, bool $escape = true): static
    {
        $values = $escape ? array_map(e(...), $values) : $values;

        PHPUnit::withResponse($this)->assertThat($values, new SeeInOrder($this->getContent()));

        return $this;
    }

    /**
     * Assert that the given HTML strings are contained in order within the response.
     */
    public function assertSeeHtmlInOrder(array $values): static
    {
        return $this->assertSeeInOrder($values, false);
    }

    /**
     * Assert that the given string or array of strings are contained within the response text.
     */
    public function assertSeeText(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(e(...), $value) : $value;

        $content = $this->decodedResponseText();

        foreach ($values as $value) {
            PHPUnit::withResponse($this)->assertStringContainsString(
                html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'),
                $content
            );
        }

        return $this;
    }

    /**
     * Assert that the given strings are contained in order within the response text.
     */
    public function assertSeeTextInOrder(array $values, bool $escape = true): static
    {
        $values = $escape ? array_map(e(...), $values) : $values;

        PHPUnit::withResponse($this)->assertThat($values, new SeeInOrder(strip_tags($this->getContent())));

        return $this;
    }

    /**
     * Assert that the given string or array of strings are not contained within the response.
     */
    public function assertDontSee(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(e(...), $value) : $value;

        foreach ($values as $value) {
            PHPUnit::withResponse($this)->assertStringNotContainsString((string) $value, $this->getContent());
        }

        return $this;
    }

    /**
     * Assert that the given HTML string or array of HTML strings are not contained within the response.
     */
    public function assertDontSeeHtml(array|string $value): static
    {
        return $this->assertDontSee($value, false);
    }

    /**
     * Assert that the given string or array of strings are not contained within the response text.
     */
    public function assertDontSeeText(array|string $value, bool $escape = true): static
    {
        $value = Arr::wrap($value);

        $values = $escape ? array_map(e(...), $value) : $value;

        $content = $this->decodedResponseText();

        foreach ($values as $value) {
            PHPUnit::withResponse($this)->assertStringNotContainsString(
                html_entity_decode((string) $value, ENT_QUOTES, 'UTF-8'),
                $content
            );
        }

        return $this;
    }

    /**
     * Get the response text with HTML entities decoded for plain-text assertions.
     */
    protected function decodedResponseText(): string
    {
        return html_entity_decode(strip_tags($this->getContent()), ENT_QUOTES, 'UTF-8');
    }

    /**
     * Assert that the response is a superset of the given JSON.
     */
    public function assertJson(array|callable $value, bool $strict = false): static
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
     * Assert that the given path in the response contains all of the expected values without looking at the order.
     */
    public function assertJsonPathCanonicalizing(string $path, array $expect): static
    {
        $this->decodeResponseJson()->assertPathCanonicalizing($path, $expect);

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
     * Assert that the response contains the given JSON fragments.
     */
    public function assertJsonFragments(array $data): static
    {
        foreach ($data as $fragment) {
            $this->assertJsonFragment($fragment);
        }

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
    public function assertJsonStructure(?array $structure = null, ?array $responseData = null): static
    {
        $this->decodeResponseJson()->assertStructure($structure, $responseData);

        return $this;
    }

    /**
     * Assert that the response has the exact JSON structure.
     */
    public function assertExactJsonStructure(?array $structure = null, ?array $responseData = null): static
    {
        $this->decodeResponseJson()->assertStructure($structure, $responseData, true);

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

        PHPUnit::withResponse($this)->assertNotEmpty($errors, 'No validation errors were provided.');

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

                if ($errorMissing) {
                    PHPUnit::withResponse($this)->fail(
                        "Failed to find a validation error in the response for key and message: '{$key}' => '{$expectedMessage}'" . PHP_EOL . PHP_EOL . $errorMessage
                    );
                }
            }
        }

        return $this;
    }

    /**
     * Assert that the response has the given JSON validation errors but does not have any other JSON validation errors.
     */
    public function assertOnlyJsonValidationErrors(array|string $errors, string $responseKey = 'errors'): static
    {
        $this->assertJsonValidationErrors($errors, $responseKey);

        $jsonErrors = Arr::get($this->json(), $responseKey) ?? [];

        $expectedErrorKeys = (new Collection($errors))
            ->map(fn ($value, $key) => is_int($key) ? $value : $key)
            ->all();

        $unexpectedErrorKeys = Arr::except($jsonErrors, $expectedErrorKeys);

        PHPUnit::withResponse($this)->assertTrue(
            count($unexpectedErrorKeys) === 0,
            'Response has unexpected validation errors: ' . (new Collection($unexpectedErrorKeys))->keys()->map(fn ($key) => "'{$key}'")->join(', ')
        );

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

        PHPUnit::withResponse($this)->assertArrayHasKey(
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
            PHPUnit::withResponse($this)->assertTrue(true);

            return $this;
        }

        $json = $this->json();

        if (! Arr::has($json, $responseKey)) {
            PHPUnit::withResponse($this)->assertTrue(true);

            return $this;
        }

        $errors = Arr::get($json, $responseKey, []);

        if (is_null($keys) && count($errors) > 0) {
            PHPUnit::withResponse($this)->fail(
                'Response has unexpected validation errors: ' . PHP_EOL . PHP_EOL
                . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        foreach (Arr::wrap($keys) as $key) {
            PHPUnit::withResponse($this)->assertFalse(
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

        PHPUnit::withResponse($this)->assertTrue(
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

        PHPUnit::withResponse($this)->assertTrue(
            is_array($data)
            && str_starts_with($encodedData, '{')
            && str_ends_with($encodedData, '}')
        );

        return $this;
    }

    /**
     * Validate the decoded response JSON.
     *
     * @throws Throwable
     */
    public function decodeResponseJson(): AssertableJsonString
    {
        if ($this->isStreamedResponse()) {
            $testJson = new AssertableJsonString($this->streamedContent());
        } else {
            $testJson = new AssertableJsonString($this->getContent());
        }

        $decodedResponse = $testJson->json();

        if (is_null($decodedResponse) || $decodedResponse === false) {
            if ($this->exception) {
                throw $this->exception;
            }
            PHPUnit::withResponse($this)->fail('Invalid JSON was returned from the route.');
        }

        return $testJson;
    }

    /**
     * Return the decoded response JSON.
     */
    public function json(?string $key = null): mixed
    {
        return $this->decodeResponseJson()->json($key);
    }

    /**
     * Get the decoded JSON body of the response as a collection.
     */
    public function collect(?string $key = null): Collection
    {
        return new Collection($this->json($key));
    }

    /**
     * Assert that the response view equals the given value.
     */
    public function assertViewIs(string $value): static
    {
        $this->ensureResponseHasView();

        PHPUnit::withResponse($this)->assertEquals($value, $this->original->name());

        return $this;
    }

    /**
     * Assert that the response view has a given piece of bound data.
     */
    public function assertViewHas(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            return $this->assertViewHasAll($key);
        }

        $this->ensureResponseHasView();

        $actual = Arr::get($this->original->gatherData(), $key);

        if (is_null($value)) {
            PHPUnit::withResponse($this)->assertTrue(Arr::has($this->original->gatherData(), $key), "Failed asserting that the data contains the key [{$key}].");
        } elseif ($value instanceof Closure) {
            PHPUnit::withResponse($this)->assertTrue($value($actual), "Failed asserting that the value at [{$key}] fulfills the expectations defined by the closure.");
        } elseif ($value instanceof Model) {
            PHPUnit::withResponse($this)->assertTrue($value->is($actual), "Failed asserting that the model at [{$key}] matches the given model.");
        } elseif ($value instanceof EloquentCollection) {
            PHPUnit::withResponse($this)->assertInstanceOf(EloquentCollection::class, $actual);
            PHPUnit::withResponse($this)->assertSameSize($value, $actual);

            $value->each(fn ($item, $index) => PHPUnit::withResponse($this)->assertTrue($actual->get($index)->is($item), "Failed asserting that the collection at [{$key}.[{$index}]]' matches the given collection."));
        } else {
            PHPUnit::withResponse($this)->assertEquals($value, $actual, "Failed asserting that [{$key}] matches the expected value.");
        }

        return $this;
    }

    /**
     * Assert that the response view has a given list of bound data.
     */
    public function assertViewHasAll(array $bindings): static
    {
        foreach ($bindings as $key => $value) {
            if (is_int($key)) {
                $this->assertViewHas($value);
            } else {
                $this->assertViewHas($key, $value);
            }
        }

        return $this;
    }

    /**
     * Get a piece of data from the original view.
     */
    public function viewData(?string $key = null): mixed
    {
        $this->ensureResponseHasView();

        $data = $this->original->gatherData();

        if (is_null($key)) {
            return $data;
        }

        return $data[$key];
    }

    /**
     * Assert that the response view is missing a piece of bound data.
     */
    public function assertViewMissing(string $key): static
    {
        $this->ensureResponseHasView();

        PHPUnit::withResponse($this)->assertFalse(Arr::has($this->original->gatherData(), $key));

        return $this;
    }

    /**
     * Ensure that the response has a view as its original content.
     */
    protected function ensureResponseHasView(): static
    {
        if (! $this->responseHasView()) {
            return PHPUnit::withResponse($this)->fail('The response is not a view.');
        }

        return $this;
    }

    /**
     * Determine if the original response is a view.
     */
    protected function responseHasView(): bool
    {
        return isset($this->original) && $this->original instanceof View;
    }

    /**
     * Assert that the given keys do not have validation errors.
     */
    public function assertValid(array|string|null $keys = null, string $errorBag = 'default', string $responseKey = 'errors'): static
    {
        if ($this->baseResponse->headers->get('Content-Type') === 'application/json') {
            return $this->assertJsonMissingValidationErrors($keys, $responseKey);
        }

        if ($this->session()->get('errors')) {
            $errors = $this->session()->get('errors')->getBag($errorBag)->getMessages();
        } else {
            $errors = [];
        }

        if (empty($errors)) {
            PHPUnit::withResponse($this)->assertTrue(true);

            return $this;
        }

        if (is_null($keys) && count($errors) > 0) {
            PHPUnit::withResponse($this)->fail(
                'Response has unexpected validation errors: ' . PHP_EOL . PHP_EOL
                . json_encode($errors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        foreach (Arr::wrap($keys) as $key) {
            PHPUnit::withResponse($this)->assertFalse(
                isset($errors[$key]),
                "Found unexpected validation error for key: '{$key}'"
            );
        }

        return $this;
    }

    /**
     * Assert that the response has the given validation errors.
     */
    public function assertInvalid(array|string|null $errors = null, string $errorBag = 'default', string $responseKey = 'errors'): static
    {
        if ($this->baseResponse->headers->get('Content-Type') === 'application/json') {
            return $this->assertJsonValidationErrors($errors, $responseKey);
        }

        $this->assertSessionHas('errors');

        $sessionErrors = $this->session()->get('errors')->getBag($errorBag)->getMessages();

        $errorMessage = $sessionErrors
            ? 'Response has the following validation errors in the session:'
                    . PHP_EOL . PHP_EOL . json_encode($sessionErrors, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL
            : 'Response does not have validation errors in the session.';

        foreach (Arr::wrap($errors) as $key => $value) {
            PHPUnit::withResponse($this)->assertArrayHasKey(
                $resolvedKey = (is_int($key)) ? $value : $key,
                $sessionErrors,
                "Failed to find a validation error in session for key: '{$resolvedKey}'" . PHP_EOL . PHP_EOL . $errorMessage
            );

            foreach (Arr::wrap($value) as $message) {
                if (! is_int($key)) {
                    $hasError = false;

                    foreach (Arr::wrap($sessionErrors[$key]) as $sessionErrorMessage) {
                        if (Str::contains($sessionErrorMessage, $message)) {
                            $hasError = true;

                            break;
                        }
                    }

                    if (! $hasError) {
                        PHPUnit::withResponse($this)->fail(
                            "Failed to find a validation error for key and message: '{$key}' => '{$message}'" . PHP_EOL . PHP_EOL . $errorMessage
                        );
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Assert that the response has the given validation errors but does not have any other validation errors.
     */
    public function assertOnlyInvalid(array|string|null $errors = null, string $errorBag = 'default', string $responseKey = 'errors'): static
    {
        if ($this->baseResponse->headers->get('Content-Type') === 'application/json') {
            return $this->assertOnlyJsonValidationErrors($errors, $responseKey);
        }

        $this->assertSessionHas('errors');

        $sessionErrors = $this->session()->get('errors')
            ->getBag($errorBag)
            ->getMessages();

        $expectedErrorKeys = (new Collection($errors))
            ->map(fn ($value, $key) => is_int($key) ? $value : $key)
            ->all();

        $unexpectedErrorKeys = Arr::except($sessionErrors, $expectedErrorKeys);

        PHPUnit::withResponse($this)->assertTrue(
            count($unexpectedErrorKeys) === 0,
            'Response has unexpected validation errors: ' . (new Collection($unexpectedErrorKeys))->keys()->map(fn ($key) => "'{$key}'")->join(', ')
        );

        return $this;
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
            PHPUnit::withResponse($this)->assertTrue(
                $this->session()->has($key),
                "Session is missing expected key [{$key}]."
            );
        } elseif ($value instanceof Closure) {
            PHPUnit::withResponse($this)->assertTrue($value($this->session()->get($key)));
        } else {
            PHPUnit::withResponse($this)->assertEquals($value, $this->session()->get($key));
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
                $this->session()->hasOldInput($key),
                "Session is missing expected key [{$key}]."
            );
        } elseif ($value instanceof Closure) {
            PHPUnit::withResponse($this)->assertTrue($value($this->session()->getOldInput($key)));
        } else {
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
     * Assert that the session has the given errors.
     */
    public function assertSessionHasErrorsIn(string $errorBag, array|string $keys = [], mixed $format = null): static
    {
        return $this->assertSessionHasErrors($keys, $format, $errorBag);
    }

    /**
     * Assert that the session does not have a given key.
     */
    public function assertSessionMissing(array|string $key, mixed $value = null): static
    {
        if (is_array($key)) {
            foreach ($key as $value) {
                $this->assertSessionMissing($value);
            }

            return $this;
        }

        if (is_null($value)) {
            PHPUnit::withResponse($this)->assertFalse(
                $this->session()->has($key),
                "Session has unexpected key [{$key}]."
            );
        } elseif ($value instanceof Closure) {
            PHPUnit::withResponse($this)->assertFalse($value($this->session()->get($key)));
        } else {
            PHPUnit::withResponse($this)->assertNotEquals($value, $this->session()->get($key));
        }

        return $this;
    }

    /**
     * Get the current session store.
     */
    protected function session(): SessionContract
    {
        $container = Container::getInstance();

        if (! $container->has(SessionContract::class)) {
            throw new RuntimeException('Package `hypervel/session` is not installed.');
        }

        return $container->make(SessionContract::class);
    }

    /**
     * Dump the headers from the response and end the script.
     */
    public function ddHeaders(): never
    {
        $this->dumpHeaders();

        exit(1);
    }

    /**
     * Dump the body of the response and end the script.
     */
    public function ddBody(?string $key = null): never
    {
        $content = $this->content();

        if (json_validate($content)) {
            $this->ddJson($key);
        }

        dd($content);
    }

    /**
     * Dump the JSON payload from the response and end the script.
     */
    public function ddJson(?string $key = null): never
    {
        dd($this->json($key));
    }

    /**
     * Dump the session from the response and end the script.
     */
    public function ddSession(array|string $keys = []): never
    {
        $this->dumpSession($keys);

        exit(1);
    }

    /**
     * Dump the content from the response.
     */
    public function dump(?string $key = null): static
    {
        $content = $this->getContent();

        $json = json_decode($content);

        if (json_last_error() === JSON_ERROR_NONE) {
            $content = $json;
        }

        if (! is_null($key)) {
            dump(data_get($content, $key));
        } else {
            dump($content);
        }

        return $this;
    }

    /**
     * Dump the headers from the response.
     */
    public function dumpHeaders(): static
    {
        dump($this->headers->all());

        return $this;
    }

    /**
     * Dump the session from the response.
     */
    public function dumpSession(array|string $keys = []): static
    {
        $keys = (array) $keys;

        if (empty($keys)) {
            dump($this->session()->all());
        } else {
            dump($this->session()->only($keys));
        }

        return $this;
    }

    /**
     * Get the streamed content from the response.
     */
    public function streamedContent(): string
    {
        if (! is_null($this->streamedContent)) {
            return $this->streamedContent;
        }

        if (! $this->isStreamedResponse()) {
            PHPUnit::withResponse($this)->fail('The response is not a streamed response.');
        }

        // Hypervel's direct Swoole streaming path writes to a FakeWritableConnection
        // in test mode. Read the captured content from it instead of output buffering.
        if ($this->baseResponse instanceof HypervelResponse && $this->baseResponse->isStreamed()) {
            $connection = $this->baseResponse->getConnection();

            if ($connection instanceof FakeWritableConnection) {
                return $this->streamedContent = $connection->getWrittenContent();
            }
        }

        $level = ob_get_level();

        ob_start(function (string $buffer): string {
            $this->streamedContent .= $buffer;

            return '';
        });

        try {
            $this->sendContent();
        } finally {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
        }

        return $this->streamedContent;
    }

    /**
     * Determine if the response is a streamed response.
     *
     * Covers both Symfony's StreamedResponse/StreamedJsonResponse and
     * Hypervel's direct Swoole streaming via Response::stream().
     */
    protected function isStreamedResponse(): bool
    {
        return $this->baseResponse instanceof StreamedResponse
            || $this->baseResponse instanceof StreamedJsonResponse // @phpstan-ignore instanceof.alwaysFalse
            || ($this->baseResponse instanceof HypervelResponse && $this->baseResponse->isStreamed());
    }

    /**
     * Set the previous exceptions on the response.
     */
    public function withExceptions(Collection $exceptions): static
    {
        $this->exceptions = $exceptions;

        return $this;
    }

    /**
     * Dynamically access base response parameters.
     */
    public function __get(string $key): mixed
    {
        return $this->baseResponse->{$key};
    }

    /**
     * Proxy isset() checks to the underlying base response.
     */
    public function __isset(string $key): bool
    {
        return isset($this->baseResponse->{$key});
    }

    /**
     * Determine if the given offset exists.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->responseHasView()
            ? isset($this->original->gatherData()[$offset])
            : isset($this->json()[$offset]);
    }

    /**
     * Get the value for a given offset.
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->responseHasView()
            ? $this->viewData($offset)
            : $this->json()[$offset];
    }

    /**
     * Set the value at the given offset.
     *
     * @throws LogicException
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw new LogicException('Response data may not be mutated using array access.');
    }

    /**
     * Unset the value at the given offset.
     *
     * @throws LogicException
     */
    public function offsetUnset(mixed $offset): void
    {
        throw new LogicException('Response data may not be mutated using array access.');
    }

    /**
     * Handle dynamic calls into macros or pass missing methods to the base response.
     */
    public function __call(string $method, array $args): mixed
    {
        if (static::hasMacro($method)) {
            return $this->macroCall($method, $args);
        }

        return $this->baseResponse->{$method}(...$args);
    }

    /**
     * Flush the test response's global state.
     */
    public static function flushState(): void
    {
        static::flushMacros();
    }
}
