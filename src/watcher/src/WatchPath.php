<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Symfony\Component\Finder\Glob;

readonly class WatchPath
{
    private ?string $regex;

    /** Whether this path requires recursive filesystem traversal. */
    public bool $recursive;

    /**
     * @param string $path Relative base path (e.g., 'app', 'config', '.env')
     * @param WatchPathType $type Whether this entry represents a directory or a file
     * @param null|string $pattern Original glob pattern for filtering
     */
    public function __construct(
        public string $path,
        public WatchPathType $type,
        public ?string $pattern = null,
    ) {
        $this->regex = $type === WatchPathType::Directory && $pattern !== null
            ? Glob::toRegex($pattern, strictLeadingDot: false)
            : null;

        if ($type === WatchPathType::File) {
            $this->recursive = false;
        } elseif ($pattern === null) {
            $this->recursive = true;
        } else {
            $suffix = $path === '.' ? $pattern : substr($pattern, strlen($path) + 1);
            // Symfony's `app/**` glob matches deep descendants despite having no slash in its suffix.
            $this->recursive = str_contains($suffix, '/') || str_contains($suffix, '**');
        }
    }

    /**
     * Determine if a relative file path matches this watch path.
     *
     * For File entries: exact match against the path.
     * For Directory entries without a pattern: matches any file under the directory.
     * For Directory entries with a pattern: matches using Symfony Glob regex.
     */
    public function matches(string $relativePath): bool
    {
        if ($this->type === WatchPathType::File) {
            return $relativePath === $this->path;
        }

        if ($this->pattern === null) {
            $directory = rtrim($this->path, '/');

            return $directory === '' || $directory === '.'
                || str_starts_with($relativePath, $directory . '/');
        }

        /** @var string $regex */
        $regex = $this->regex;

        return (bool) preg_match($regex, $relativePath);
    }
}
