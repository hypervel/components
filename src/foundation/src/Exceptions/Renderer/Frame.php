<?php

declare(strict_types=1);

namespace Hypervel\Foundation\Exceptions\Renderer;

use Hypervel\Foundation\Concerns\ResolvesDumpSource;
use Symfony\Component\ErrorHandler\Exception\FlattenException;

use function Hypervel\Filesystem\join_paths;

class Frame
{
    use ResolvesDumpSource;

    /**
     * The compiled view path (required by ResolvesDumpSource, unused by Frame).
     */
    protected string $compiledViewPath = '';

    /**
     * Whether this frame is the main (first non-vendor) frame.
     */
    protected bool $isMain = false;

    /**
     * Create a new frame instance.
     *
     * @param FlattenException $exception The "flattened" exception instance
     * @param array<string, string> $classMap The application's class map
     * @param array{file?: string, line?: int, class?: string, type?: string, function?: string, args?: array} $frame The frame's raw data from the flattened exception
     */
    public function __construct(
        protected FlattenException $exception,
        protected array $classMap,
        protected array $frame,
        protected string $basePath,
        protected ?Frame $previous = null,
    ) {
    }

    /**
     * Get the frame's source / origin.
     */
    public function source(): string
    {
        return match (true) {
            is_string($this->class()) => $this->class(),
            default => $this->file(),
        };
    }

    /**
     * Get the frame's editor link.
     */
    public function editorHref(): ?string
    {
        return $this->resolveSourceHref($this->frame['file'], $this->line());
    }

    /**
     * Get the frame's class, if any.
     */
    public function class(): ?string
    {
        if (! empty($this->frame['class'])) {
            return $this->frame['class'];
        }

        $class = array_search((string) realpath($this->frame['file']), $this->classMap, true);

        return $class === false ? null : $class;
    }

    /**
     * Get the frame's file.
     */
    public function file(): string
    {
        return match (true) {
            ! isset($this->frame['file']) => '[internal function]',
            ! is_string($this->frame['file']) => '[unknown file]', // @phpstan-ignore booleanNot.alwaysFalse (defensive, matches Laravel)
            default => str_replace($this->basePath . DIRECTORY_SEPARATOR, '', $this->frame['file']),
        };
    }

    /**
     * Get the frame's line number.
     */
    public function line(): int
    {
        if (! is_file($this->frame['file']) || ! is_readable($this->frame['file'])) {
            return 0;
        }

        $maxLines = count(file($this->frame['file']) ?: []);

        return $this->frame['line'] > $maxLines ? 1 : $this->frame['line'];
    }

    /**
     * Get the frame's function operator.
     *
     * @return ''|'->'|'::'
     */
    public function operator(): string
    {
        return $this->frame['type'] ?? '';
    }

    /**
     * Get the frame's function or method.
     */
    public function callable(): string
    {
        return match (true) {
            ! empty($this->frame['function']) => $this->frame['function'],
            default => 'throw',
        };
    }

    /**
     * Get the frame's arguments.
     */
    public function args(): array
    {
        if (! isset($this->frame['args']) || ! is_array($this->frame['args']) || count($this->frame['args']) === 0) { // @phpstan-ignore booleanNot.alwaysFalse (defensive, no native type enforcement)
            return [];
        }

        return array_map(function ($argument) {
            [$key, $value] = $argument;

            return match ($key) {
                'object' => "{$key}({$value})",
                default => $key,
            };
        }, $this->frame['args']);
    }

    /**
     * Get the frame's code snippet.
     */
    public function snippet(): string
    {
        if (! is_file($this->frame['file']) || ! is_readable($this->frame['file'])) {
            return '';
        }

        $contents = file($this->frame['file']) ?: [];

        $start = max($this->line() - 6, 0);

        $length = 8 * 2 + 1;

        return implode('', array_slice($contents, $start, $length));
    }

    /**
     * Determine if the frame is from the vendor directory.
     */
    public function isFromVendor(): bool
    {
        return ! str_starts_with($this->frame['file'], $this->basePath)
            || str_starts_with($this->frame['file'], join_paths($this->basePath, 'vendor'));
    }

    /**
     * Get the previous frame.
     */
    public function previous(): ?Frame
    {
        return $this->previous;
    }

    /**
     * Mark this frame as the main frame.
     */
    public function markAsMain(): void
    {
        $this->isMain = true;
    }

    /**
     * Determine if this is the main frame.
     */
    public function isMain(): bool
    {
        return $this->isMain;
    }
}
