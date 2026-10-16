<?php

declare(strict_types=1);

namespace Hypervel\Mcp;

use Hypervel\Container\Container;
use Hypervel\Mcp\Enums\Role;
use Hypervel\Mcp\Schema\Icon;
use Hypervel\Mcp\Server\Content\Audio;
use Hypervel\Mcp\Server\Content\Blob;
use Hypervel\Mcp\Server\Content\Image;
use Hypervel\Mcp\Server\Content\Notification;
use Hypervel\Mcp\Server\Content\ResourceLink;
use Hypervel\Mcp\Server\Content\StructuredContentText;
use Hypervel\Mcp\Server\Content\Text;
use Hypervel\Mcp\Server\Contracts\Content;
use Hypervel\Mcp\Server\Resource as McpResource;
use Hypervel\Support\Facades\Storage;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use InvalidArgumentException;
use JsonException;
use League\Flysystem\UnableToReadFile;

class Response
{
    use Conditionable;
    use Macroable;

    /**
     * Create a response with its content and role.
     */
    protected function __construct(
        protected Content $content,
        protected Role $role = Role::User,
        protected bool $isError = false,
    ) {
    }

    /**
     * Create a notification response.
     *
     * @param array<string, mixed> $params
     */
    public static function notification(string $method, array $params = []): static
    {
        return new static(new Notification($method, $params));
    }

    /**
     * Create a text response.
     */
    public static function text(string $text): static
    {
        return new static(new Text($text));
    }

    /**
     * Create a response from an HTML file.
     */
    public static function html(string $path): static
    {
        $path = str_starts_with($path, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $path) ? $path : resource_path($path);

        if (! file_exists($path)) {
            throw new InvalidArgumentException("File not found at path [{$path}].");
        }

        return static::text((string) file_get_contents($path));
    }

    /**
     * Create a response from a rendered view.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $mergeData
     */
    public static function view(string $view, array $data = [], array $mergeData = []): static
    {
        return static::text(view($view, $data, $mergeData)->render());
    }

    /**
     * Create a response containing JSON text.
     *
     * @internal
     *
     * @throws JsonException
     */
    public static function json(mixed $content): static
    {
        return static::text(json_encode($content, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Create a binary response.
     */
    public static function blob(string $content): static
    {
        return new static(new Blob($content));
    }

    /**
     * Create a structured response with a text representation.
     *
     * @param array<string, mixed> $response
     */
    public static function structured(array $response): ResponseFactory
    {
        if ($response === []) {
            throw new InvalidArgumentException('Structured content cannot be empty.');
        }

        try {
            $json = json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException $jsonException) {
            throw new InvalidArgumentException("Invalid structured content: {$jsonException->getMessage()}", 0, $jsonException);
        }

        $content = new static(new StructuredContentText($json));

        return (new ResponseFactory($content))->withStructuredContent($response);
    }

    /**
     * Create an error response.
     */
    public static function error(string $text): static
    {
        return new static(new Text($text), isError: true);
    }

    /**
     * Get the response content.
     */
    public function content(): Content
    {
        return $this->content;
    }

    /**
     * Create a response factory.
     *
     * @param array<int, Response>|Response $responses
     */
    public static function make(Response|array $responses): ResponseFactory
    {
        return new ResponseFactory($responses);
    }

    /**
     * Add metadata to the response content.
     *
     * @param array<string, mixed>|string $meta
     */
    public function withMeta(array|string $meta, mixed $value = null): static
    {
        $this->content->setMeta($meta, $value);

        return $this;
    }

    /**
     * Create an audio response.
     */
    public static function audio(string $data, string $mimeType = 'audio/wav'): static
    {
        return new static(new Audio($data, $mimeType));
    }

    /**
     * Create an image response.
     */
    public static function image(string $data, string $mimeType = 'image/png'): static
    {
        return new static(new Image($data, $mimeType));
    }

    /**
     * Create a resource link response.
     *
     * @param class-string<McpResource>|McpResource|ResourceLink|string $uri
     * @param array<string, mixed> $annotations
     * @param list<Icon> $icons
     */
    public static function resourceLink(
        string|McpResource|ResourceLink $uri,
        ?string $name = null,
        ?string $mimeType = null,
        ?string $title = null,
        ?string $description = null,
        ?int $size = null,
        array $annotations = [],
        array $icons = [],
    ): static {
        if (is_string($uri) && is_subclass_of($uri, McpResource::class)) {
            $uri = Container::getInstance()->make($uri);
        }

        $link = match (true) {
            $uri instanceof ResourceLink => $uri,
            $uri instanceof McpResource => (new ResourceLink(
                uri: $uri->uri(),
                name: $name ?? $uri->name(),
                mimeType: $mimeType ?? $uri->mimeType(),
                title: $title ?? $uri->title(),
                description: $description ?? $uri->description(),
                size: $size,
                annotations: array_merge($uri->annotations(), $annotations),
                icons: $icons === [] ? $uri->resolvedIcons() : $icons,
            )),
            default => new ResourceLink(
                uri: $uri,
                name: $name ?? throw new InvalidArgumentException('Resource link name is required when using a URI string.'),
                mimeType: $mimeType,
                title: $title,
                description: $description,
                size: $size,
                annotations: $annotations,
                icons: $icons,
            ),
        };

        return new static($link);
    }

    /**
     * Create an image or audio response from a filesystem disk.
     */
    public static function fromStorage(string $path, ?string $disk = null, ?string $mimeType = null): static
    {
        $storage = Storage::disk($disk);

        try {
            $data = $storage->get($path);
        } catch (UnableToReadFile $unableToReadFile) {
            throw new InvalidArgumentException("File not found at path [{$path}].", 0, $unableToReadFile);
        }

        if ($data === null) {
            throw new InvalidArgumentException("File not found at path [{$path}].");
        }

        // The contract omits adapter metadata methods, which every shipped disk provides.
        // @phpstan-ignore method.notFound
        $mimeType ??= $storage->mimeType($path) ?: throw new InvalidArgumentException(
            "Unable to determine MIME type for [{$path}].",
        );

        return match (true) {
            str_starts_with($mimeType, 'image/') => static::image($data, $mimeType),
            str_starts_with($mimeType, 'audio/') => static::audio($data, $mimeType),
            default => throw new InvalidArgumentException("Unsupported MIME type [{$mimeType}] for [{$path}]."),
        };
    }

    /**
     * Create a response with the assistant role.
     */
    public function asAssistant(): static
    {
        return new static($this->content, Role::Assistant, $this->isError);
    }

    /**
     * Determine whether the response is a notification.
     */
    public function isNotification(): bool
    {
        return $this->content instanceof Notification;
    }

    /**
     * Determine whether the response represents an error.
     */
    public function isError(): bool
    {
        return $this->isError;
    }

    /**
     * Get the response role.
     */
    public function role(): Role
    {
        return $this->role;
    }
}
