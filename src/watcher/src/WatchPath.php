<?php

declare(strict_types=1);

namespace Hypervel\Watcher;

use Symfony\Component\Finder\Glob;

readonly class WatchPath
{
    private ?string $regex;

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
