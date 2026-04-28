<?php

declare(strict_types=1);

namespace Hypervel\Inertia\Ssr;

use Hypervel\Foundation\Events\Dispatchable;

class SsrRenderFailed
{
    use Dispatchable;

    /**
     * Create a new event instance.
     *
     * @param array<string, mixed> $page The page data that was being rendered
     * @param string $error The error message
     * @param SsrErrorType $type The error type
     * @param null|string $hint A helpful hint on how to fix the error
     * @param null|string $browserApi The browser API that was accessed (if type is browser-api)
     * @param null|string $stack The stack trace
     * @param null|string $sourceLocation The source location (file:line:column) where the error occurred
     */
    public function __construct(
        public readonly array $page,
        public readonly string $error,
        public readonly SsrErrorType $type = SsrErrorType::Unknown,
        public readonly ?string $hint = null,
        public readonly ?string $browserApi = null,
        public readonly ?string $stack = null,
        public readonly ?string $sourceLocation = null,
    ) {
    }

    /**
     * Get the component name from the page data.
     */
    public function component(): string
    {
        return $this->page['component'] ?? 'Unknown';
    }

    /**
     * Get the URL from the page data.
     */
    public function url(): string
    {
        return $this->page['url'] ?? '/';
    }

    /**
     * Convert the event to an array for logging.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'component' => $this->component(),
            'url' => $this->url(),
            'error' => $this->error,
            'type' => $this->type->value,
            'hint' => $this->hint,
            'browser_api' => $this->browserApi,
            'source_location' => $this->sourceLocation,
        ]);
    }
}
