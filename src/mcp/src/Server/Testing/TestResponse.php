<?php

declare(strict_types=1);

namespace Hypervel\Mcp\Server\Testing;

use Closure;
use Hypervel\Container\Container;
use Hypervel\Contracts\Auth\Authenticatable;
use Hypervel\Mcp\Exceptions\JsonRpcException;
use Hypervel\Mcp\Server\Primitive;
use Hypervel\Mcp\Server\Prompt;
use Hypervel\Mcp\Server\Resource;
use Hypervel\Mcp\Server\Tool;
use Hypervel\Mcp\Transport\JsonRpcResponse;
use Hypervel\Support\Json;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use Hypervel\Testing\AssertableJsonString;
use Hypervel\Testing\Fluent\AssertableJson;
use PHPUnit\Framework\Assert;
use RuntimeException;
use stdClass;

use function Hypervel\Support\enum_value;

class TestResponse
{
    use Conditionable;
    use Macroable;

    protected JsonRpcResponse $response;

    /**
     * @var array<int, JsonRpcResponse>
     */
    protected array $notifications = [];

    /**
     * Consume a primitive's response and collect its notifications.
     *
     * @param iterable<int, JsonRpcResponse>|JsonRpcResponse $response
     */
    public function __construct(
        protected Primitive $primitive,
        iterable|JsonRpcResponse $response,
        protected ?string $requestedUri = null,
    ) {
        try {
            foreach (is_iterable($response) ? $response : [$response] as $message) {
                $content = $message->toArray();

                if (isset($content['id']) || isset($content['error'])) {
                    $this->response = $message;
                } else {
                    $this->notifications[] = $message;
                }
            }
        } catch (JsonRpcException $exception) {
            // Lazy prompt and resource failures surface after earlier notifications.
            $this->response = $exception->toJsonRpcResponse();
        }
    }

    /**
     * Assert that the response contains the given text.
     *
     * @param array<string>|string $text
     */
    public function assertSee(array|string $text): static
    {
        $seeable = collect([
            ...$this->content(),
            ...$this->errors(),
        ])->filter(fn (string $message): bool => $message !== '')->unique()->values()->all();

        foreach (is_array($text) ? $text : [$text] as $segment) {
            foreach ($seeable as $message) {
                if (str_contains($message, $segment)) {
                    continue 2;
                }
            }

            // @phpstan-ignore-next-line
            Assert::assertTrue(false, "The expected text [{$segment}] was not found in the response content.");
        }

        // @phpstan-ignore-next-line
        Assert::assertTrue(true);

        return $this;
    }

    /**
     * Assert that the response does not contain the given text.
     *
     * @param array<string>|string $text
     */
    public function assertDontSee(array|string $text): static
    {
        $seeable = collect([
            ...$this->content(),
            ...$this->errors(),
        ])->filter(fn (string $message): bool => $message !== '')->unique()->values()->all();

        foreach (is_array($text) ? $text : [$text] as $segment) {
            foreach ($seeable as $message) {
                if (str_contains($message, $segment)) {
                    // @phpstan-ignore-next-line
                    Assert::assertTrue(false, "The unexpected text [{$segment}] was found in the response content.");

                    return $this;
                }
            }
        }

        // @phpstan-ignore-next-line
        Assert::assertTrue(true);

        return $this;
    }

    /**
     * Assert that the response contains the given structured content.
     *
     * @param array<string, mixed>|Closure(AssertableJson): mixed $structuredContent
     */
    public function assertStructuredContent(Closure|array $structuredContent): static
    {
        $actual = $this->structuredContent();

        if ($structuredContent instanceof Closure) {
            Assert::assertNotNull($actual, 'The response does not contain any structured content.');

            $assertableJson = AssertableJson::fromArray($actual);

            $structuredContent($assertableJson);

            $assertableJson->interacted();

            return $this;
        }

        Assert::assertSame(
            $this->toJsonRepresentation($structuredContent),
            $actual,
            'The expected structured content does not match the actual structured content.'
        );

        return $this;
    }

    /**
     * Assert that the structured content has the expected count of items at the given key.
     */
    public function assertStructuredContentCount(int $count, ?string $key = null): static
    {
        $actual = $this->structuredContent();

        Assert::assertNotNull($actual, 'The response does not contain any structured content.');

        Assert::assertCount($count, data_get($actual, $key), "The expected structured content count [{$count}] does not match the actual count.");

        return $this;
    }

    /**
     * Assert that the expected value and type exists at the given path in the structured content.
     *
     * @param (Closure(mixed): bool)|mixed $expect
     */
    public function assertStructuredContentPath(string $path, mixed $expect): static
    {
        $actual = $this->structuredContent();

        Assert::assertNotNull($actual, 'The response does not contain any structured content.');

        $value = data_get($actual, $path);

        if ($expect instanceof Closure) {
            Assert::assertTrue($expect($value), "The structured content at [{$path}] does not pass the given truth test.");

            return $this;
        }

        Assert::assertSame(
            enum_value($expect),
            $value,
            "The expected structured content at [{$path}] does not match the actual value."
        );

        return $this;
    }

    /**
     * Assert that the expected values and types exist at the given paths in the structured content.
     *
     * @param array<string, mixed> $paths
     */
    public function assertStructuredContentPaths(array $paths): static
    {
        foreach ($paths as $path => $expected) {
            $this->assertStructuredContentPath($path, $expected);
        }

        return $this;
    }

    /**
     * Assert that the given path in the structured content contains all of the expected values without looking at the order.
     *
     * @param array<array-key, mixed> $expect
     */
    public function assertStructuredContentPathCanonicalizing(string $path, array $expect): static
    {
        $this->assertableStructuredContent()->assertPathCanonicalizing($path, $expect);

        return $this;
    }

    /**
     * Assert that the given paths in the structured content contain all of the expected values without looking at the order.
     *
     * @param array<string, array<array-key, mixed>> $paths
     */
    public function assertStructuredContentPathsCanonicalizing(array $paths): static
    {
        foreach ($paths as $path => $expected) {
            $this->assertStructuredContentPathCanonicalizing($path, $expected);
        }

        return $this;
    }

    /**
     * Assert that the structured content is exactly the given JSON.
     *
     * @param array<array-key, mixed> $data
     */
    public function assertExactStructuredContent(array $data): static
    {
        $this->assertableStructuredContent()->assertExact($data);

        return $this;
    }

    /**
     * Assert that the structured content has the similar JSON as given.
     *
     * @param array<array-key, mixed> $data
     */
    public function assertSimilarStructuredContent(array $data): static
    {
        $this->assertableStructuredContent()->assertSimilar($data);

        return $this;
    }

    /**
     * Assert that the structured content contains the given JSON fragment.
     *
     * @param array<array-key, mixed> $data
     */
    public function assertStructuredContentFragment(array $data): static
    {
        $this->assertableStructuredContent()->assertFragment($data);

        return $this;
    }

    /**
     * Assert that the structured content contains the given JSON fragments.
     *
     * @param array<array-key, array<array-key, mixed>> $data
     */
    public function assertStructuredContentFragments(array $data): static
    {
        foreach ($data as $fragment) {
            $this->assertStructuredContentFragment($fragment);
        }

        return $this;
    }

    /**
     * Assert that the structured content does not contain the given JSON fragment.
     *
     * @param array<array-key, mixed> $data
     */
    public function assertStructuredContentMissing(array $data, bool $exact = false): static
    {
        $this->assertableStructuredContent()->assertMissing($data, $exact);

        return $this;
    }

    /**
     * Assert that the structured content does not contain the exact JSON fragment.
     *
     * @param array<array-key, mixed> $data
     */
    public function assertStructuredContentMissingExact(array $data): static
    {
        $this->assertableStructuredContent()->assertMissingExact($data);

        return $this;
    }

    /**
     * Assert that the structured content does not contain the given path.
     */
    public function assertStructuredContentMissingPath(string $path): static
    {
        $this->assertableStructuredContent()->assertMissingPath($path);

        return $this;
    }

    /**
     * Assert that the structured content does not contain the given paths.
     *
     * @param array<int, string> $paths
     */
    public function assertStructuredContentMissingPaths(array $paths): static
    {
        foreach ($paths as $path) {
            $this->assertStructuredContentMissingPath($path);
        }

        return $this;
    }

    /**
     * Assert that the structured content has a given JSON structure.
     *
     * @param null|array<array-key, mixed> $structure
     * @param null|array<array-key, mixed> $responseData
     */
    public function assertStructuredContentStructure(?array $structure = null, ?array $responseData = null): static
    {
        $this->assertableStructuredContent()->assertStructure($structure, $responseData);

        return $this;
    }

    /**
     * Assert that the structured content has the exact JSON structure.
     *
     * @param null|array<array-key, mixed> $structure
     * @param null|array<array-key, mixed> $responseData
     */
    public function assertExactStructuredContentStructure(?array $structure = null, ?array $responseData = null): static
    {
        $this->assertableStructuredContent()->assertStructure($structure, $responseData, true);

        return $this;
    }

    /**
     * Assert that the given key in the structured content is a JSON array.
     */
    public function assertStructuredContentIsArray(?string $key = null): static
    {
        // Associative decoding turns {} and {"0": ...} into PHP lists, so check the JSON's own types.
        $data = $this->assertableStructuredContent()->object($key);

        Assert::assertTrue(is_array($data), 'The structured content is not an array.');

        return $this;
    }

    /**
     * Assert that the given key in the structured content is a JSON object.
     */
    public function assertStructuredContentIsObject(?string $key = null): static
    {
        // Associative decoding turns {} and {"0": ...} into PHP lists, so check the JSON's own types.
        $data = $this->assertableStructuredContent()->object($key);

        Assert::assertTrue($data instanceof stdClass, 'The structured content is not an object.');

        return $this;
    }

    /**
     * Assert the number of notifications received.
     */
    public function assertNotificationCount(int $count): static
    {
        Assert::assertCount($count, $this->notifications, "The expected number of notifications [{$count}] does not match the actual count.");

        return $this;
    }

    /**
     * Assert that the given notification was received.
     *
     * @param null|array<string, mixed> $params
     */
    public function assertSentNotification(string $method, ?array $params = null): static
    {
        foreach ($this->notifications as $notification) {
            $content = $notification->toArray();

            if ($content['method'] === $method && (is_array($params) === false || $this->toJsonRepresentation($content['params']) === $this->toJsonRepresentation($params))) {
                Assert::assertTrue(true); // @phpstan-ignore-line

                return $this;
            }
        }

        Assert::fail("The expected notification [{$method}], but it was not found.");
    }

    /**
     * Assert the primitive's name.
     */
    public function assertName(string $name): static
    {
        Assert::assertEquals(
            $name,
            $this->primitive->name(),
            "The expected name [{$name}] does not match the actual name [{$this->primitive->name()}].",
        );

        return $this;
    }

    /**
     * Assert the primitive's title.
     */
    public function assertTitle(string $title): static
    {
        Assert::assertEquals(
            $title,
            $this->primitive->title(),
            "The expected title [{$title}] does not match the actual title [{$this->primitive->title()}].",
        );

        return $this;
    }

    /**
     * Assert the primitive's description.
     */
    public function assertDescription(string $description): static
    {
        Assert::assertEquals(
            $description,
            $this->primitive->description(),
            "The expected description [{$description}] does not match the actual description [{$this->primitive->description()}].",
        );

        return $this;
    }

    /**
     * Assert that the response has no errors.
     */
    public function assertOk(): static
    {
        return $this->assertHasNoErrors();
    }

    /**
     * Assert that the primitive is not registered.
     */
    public function assertNotRegistered(): static
    {
        [$primitiveType, $primitiveIdentifier] = match (true) {
            $this->primitive instanceof Tool => ['tool', $this->primitive->name()],
            $this->primitive instanceof Prompt => ['prompt', $this->primitive->name()],
            $this->primitive instanceof Resource => ['resource', $this->requestedUri ?? $this->primitive->uri()],
            default => throw new RuntimeException('This primitive type is not supported.'),
        };

        Assert::assertTrue(
            count(array_filter($this->errors(), fn (string $error): bool => str_contains($error, ucfirst($primitiveType) . ' [' . $primitiveIdentifier . '] not found.'))) > 0,
            'The ' . $primitiveType . ' [' . $this->primitive::class . '] is registered.',
        );

        return $this;
    }

    /**
     * Assert that the response has no errors.
     */
    public function assertHasNoErrors(): static
    {
        Assert::assertSame([], $this->errors(), 'The response has errors.');

        return $this;
    }

    /**
     * Assert that the response contains errors matching the given messages.
     *
     * @param array<string> $messages
     */
    public function assertHasErrors(array $messages = []): static
    {
        $errors = $this->errors();

        Assert::assertNotEmpty($errors, 'The response has no errors.');

        foreach ($messages as $message) {
            foreach ($errors as $error) {
                if (str_contains($error, $message)) {
                    continue 2;
                }
            }

            Assert::fail("The expected error message [{$message}] was not found in the response.");
        }

        return $this;
    }

    /**
     * Assert that the current user is authenticated.
     */
    public function assertAuthenticated(?string $guard = null): static
    {
        Assert::assertTrue($this->isAuthenticated($guard), 'The user is not authenticated');

        return $this;
    }

    /**
     * Assert that the current user is a guest.
     */
    public function assertGuest(?string $guard = null): static
    {
        Assert::assertFalse($this->isAuthenticated($guard), 'The user is authenticated');

        return $this;
    }

    /**
     * Assert that the current user matches the given user.
     */
    public function assertAuthenticatedAs(Authenticatable $user, ?string $guard = null): static
    {
        $expected = Container::getInstance()->make('auth')->guard($guard)->user();

        Assert::assertNotNull($expected, 'The current user is not authenticated.');

        Assert::assertInstanceOf(
            $expected::class,
            $user,
            'The currently authenticated user is not who was expected'
        );

        Assert::assertSame(
            $expected->getAuthIdentifier(),
            $user->getAuthIdentifier(),
            'The currently authenticated user is not who was expected'
        );

        return $this;
    }

    /**
     * Determine whether the current user is authenticated.
     */
    protected function isAuthenticated(?string $guard = null): bool
    {
        return Container::getInstance()->make('auth')->guard($guard)->check();
    }

    /**
     * Assert that the response contains the expected completions.
     *
     * @param array<int, string> $expectedValues
     */
    public function assertHasCompletions(array $expectedValues = []): static
    {
        $actualValues = $this->completionValues();

        foreach ($expectedValues as $expected) {
            Assert::assertContains(
                $expected,
                $actualValues,
                "Expected completion value [{$expected}] not found."
            );
        }

        return $this;
    }

    /**
     * Assert the complete set of completion values.
     *
     * @param array<int, string> $values
     */
    public function assertCompletionValues(array $values): static
    {
        Assert::assertEquals(
            $values,
            $this->completionValues(),
            'Completion values do not match expected values.'
        );

        return $this;
    }

    /**
     * Assert the number of completion values.
     */
    public function assertCompletionCount(int $count): static
    {
        $values = $this->completionValues();

        Assert::assertCount(
            $count,
            $values,
            "Expected {$count} completions, but got " . count($values)
        );

        return $this;
    }

    /**
     * Dump the response and end execution.
     */
    public function dd(): void
    {
        dd($this->response->toArray());
    }

    /**
     * Dump the response.
     */
    public function dump(): static
    {
        dump($this->response->toArray());

        return $this;
    }

    /**
     * Dump the response errors and end execution.
     */
    public function ddErrors(): void
    {
        dd($this->errors());
    }

    /**
     * Get the primitive's textual response content.
     *
     * @return array<int, string>
     */
    protected function content(): array
    {
        return (match (true) {
            // @phpstan-ignore-next-line
            $this->primitive instanceof Tool => collect($this->response->toArray()['result']['content'] ?? [])
                ->map(fn (array $message): string => $message['text'] ?? $message['data'] ?? ''),
            // @phpstan-ignore-next-line
            $this->primitive instanceof Prompt => collect($this->response->toArray()['result']['messages'] ?? [])
                ->map(fn (array $message): array => $message['content'])
                ->map(fn (array $content): string => $content['text'] ?? $content['data'] ?? ''),
            // @phpstan-ignore-next-line
            $this->primitive instanceof Resource => collect($this->response->toArray()['result']['contents'] ?? [])
                ->map(fn (array $item): string => $item['text'] ?? $item['blob'] ?? ''),
            default => throw new RuntimeException('This primitive type is not supported.'),
        })->filter(fn (string $message): bool => $message !== '')->unique()->values()->all();
    }

    /**
     * Get the response's error messages.
     *
     * @return array<int, string>
     */
    protected function errors(): array
    {
        $response = $this->response->toArray();

        if (data_get($response, 'result.isError', false)) {
            return $this->content() ?: [''];
        }

        if (array_key_exists('error', $response)) {
            return [$response['error']['message']];
        }

        return [];
    }

    /**
     * Get the response's completion values.
     *
     * @return array<int, string>
     */
    protected function completionValues(): array
    {
        $response = $this->response->toArray();

        Assert::assertNotNull(
            $response['result']['completion'] ?? null,
            'No completion data found in response.'
        );

        return $response['result']['completion']['values'] ?? [];
    }

    /**
     * Get the response's structured content.
     *
     * @return null|array<string, mixed>
     */
    protected function structuredContent(): ?array
    {
        $structuredContent = $this->response->toArray()['result']['structuredContent'] ?? null;

        if (! is_array($structuredContent) && ! $structuredContent instanceof stdClass) {
            return null;
        }

        /** @var array<string, mixed> $decoded */
        $decoded = $this->toJsonRepresentation($structuredContent);

        return $decoded;
    }

    /**
     * Get the response's structured content for JSON assertions.
     */
    protected function assertableStructuredContent(): AssertableJsonString
    {
        $structuredContent = $this->response->toArray()['result']['structuredContent'] ?? null;

        Assert::assertTrue(
            is_array($structuredContent) || $structuredContent instanceof stdClass,
            'The response does not contain any structured content.'
        );

        // Pass encoded JSON so object() can still tell empty and numeric-key objects from arrays.
        return new AssertableJsonString(Json::encode($structuredContent));
    }

    /**
     * Normalize a value through its JSON representation.
     */
    protected function toJsonRepresentation(mixed $value): mixed
    {
        return json_decode(
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
