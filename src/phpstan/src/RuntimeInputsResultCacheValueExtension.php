<?php

declare(strict_types=1);

namespace Hypervel\PHPStan;

use FilesystemIterator;
use Hypervel\PHPStan\Support\EnvironmentSnapshot;
use Hypervel\PHPStan\Support\RecursiveDirectoryIterator;
use PHPStan\Analyser\ResultCache\ResultCacheValueExtension;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use RecursiveIteratorIterator;
use SplFileInfo;

use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function explode;
use function fnmatch;
use function hash;
use function hash_file;
use function implode;
use function is_dir;
use function is_file;
use function ksort;
use function sort;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * The inputs the booted application was built from: the files the boot ran or read, the
 * environment variables it can read and the files whose presence it checks.
 *
 * PHPStan compares cached values before anything boots, so the value is computed from the key
 * alone. One aggregated value keeps each boot-derived lookup to a single dependency record.
 * Paths under the working directory are relative, which keeps the key small (PHPStan hashes
 * it on every record) and lets the result cache survive a moved checkout.
 */
#[AutowiredService]
class RuntimeInputsResultCacheValueExtension implements ResultCacheValueExtension
{
    private const string FILE = 'file';

    private const string PRESENCE = 'exists';

    private const string DIRECTORY = 'directory';

    private const string LINKED_DIRECTORY = 'linked-directory';

    private const string LISTING = 'listing';

    private const string VARIABLE = 'env';

    private const string VARIABLE_PATTERN = 'env-pattern';

    /**
     * The computed values, by key digest.
     *
     * @var array<string, string>
     */
    private array $values = [];

    /**
     * Create a new extension instance.
     */
    public function __construct(
        #[AutowiredParameter(ref: '%currentWorkingDirectory%')]
        private string $workingDirectory,
    ) {
    }

    /**
     * Create the key for the given inputs.
     *
     * @param list<string> $files files compared by content
     * @param list<string> $presenceFiles files compared only by whether they exist
     * @param array<string, string> $directories directories mapped to the fnmatch() pattern of the files compared in them, recursively
     * @param array<string, string> $linkedDirectories directories compared as $directories are, also through their symlinked directories
     * @param list<string> $listings directories compared by the names of the files in them, recursively, also through their symlinked directories
     * @param list<string> $variables environment variable names
     * @param list<string> $variablePatterns fnmatch() patterns of environment variable names, matched when compared
     */
    public function createKey(
        array $files = [],
        array $presenceFiles = [],
        array $directories = [],
        array $linkedDirectories = [],
        array $listings = [],
        array $variables = [],
        array $variablePatterns = [],
    ): string {
        $lines = [];

        foreach ([
            self::FILE => array_map($this->relativePath(...), $files),
            self::PRESENCE => array_map($this->relativePath(...), $presenceFiles),
            self::DIRECTORY => $this->directoryEntries($directories),
            self::LINKED_DIRECTORY => $this->directoryEntries($linkedDirectories),
            self::LISTING => array_map($this->relativePath(...), $listings),
            self::VARIABLE => $variables,
            self::VARIABLE_PATTERN => $variablePatterns,
        ] as $type => $entries) {
            $entries = array_values(array_unique($entries));
            sort($entries);

            foreach ($entries as $entry) {
                $lines[] = $type . "\t" . $entry;
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Get the digest of a file's contents, or 'missing'.
     */
    public static function fileDigest(string $path): string
    {
        return is_file($path) ? (string) hash_file('xxh128', $path) : 'missing';
    }

    /**
     * Get the digest of the names and contents of the files in a directory, recursively, whose names match the pattern.
     */
    public static function directoryDigest(string $directory, string $pattern, bool $followLinks = false): string
    {
        if (! is_dir($directory)) {
            return 'missing';
        }

        $lines = [];

        foreach (self::files($directory, $pattern, $followLinks) as $name => $path) {
            $lines[] = $name . "\t" . self::fileDigest($path);
        }

        return hash('xxh128', implode("\n", $lines));
    }

    /**
     * Get the digest of the names of the files in a directory, recursively, or 'missing'.
     *
     * The view finder finds views through symlinked directories, so the listing follows them.
     */
    public static function listingDigest(string $directory): string
    {
        return is_dir($directory) ? hash('xxh128', implode("\n", array_keys(self::files($directory, '*', followLinks: true)))) : 'missing';
    }

    /**
     * Get the current value for the key.
     */
    public function getValue(string $key): string
    {
        $digest = hash('xxh128', $key);

        if (isset($this->values[$digest])) {
            return $this->values[$digest];
        }

        $parts = [];
        $variables = [];
        $variablePatterns = [];

        foreach ($this->entries($key) as [$type, $entry]) {
            match ($type) {
                self::FILE => $parts[] = $entry . "\t" . self::fileDigest($this->absolutePath($entry)),
                self::PRESENCE => $parts[] = $entry . "\t" . (is_file($this->absolutePath($entry)) ? 'present' : 'missing'),
                self::DIRECTORY, self::LINKED_DIRECTORY => $parts[] = $entry . "\t" . $this->directoryEntryDigest($entry, $type === self::LINKED_DIRECTORY),
                self::LISTING => $parts[] = $entry . "\t" . self::listingDigest($this->absolutePath($entry)),
                self::VARIABLE => $variables[] = $entry,
                self::VARIABLE_PATTERN => $variablePatterns[] = $entry,
            };
        }

        $parts[] = EnvironmentSnapshot::digest($variables, $variablePatterns);

        return $this->values[$digest] = hash('xxh128', implode("\n", $parts));
    }

    /**
     * Get the key as the result cache stores it; its paths are already relative to the working directory.
     */
    public function keyToResultCache(string $key): string
    {
        return $key;
    }

    /**
     * Get the key back from the result cache.
     */
    public function keyFromResultCache(string $storedKey): string
    {
        return $storedKey;
    }

    /**
     * Get the files in a directory, recursively, whose names match the pattern, by their path relative to it, sorted.
     *
     * @return array<string, string>
     */
    private static function files(string $directory, string $pattern, bool $followLinks = false): array
    {
        $files = [];
        $directories = $followLinks
            ? RecursiveDirectoryIterator::followingLinks($directory)
            : new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS);

        foreach (new RecursiveIteratorIterator($directories) as $file) {
            if ($file instanceof SplFileInfo && $file->isFile() && fnmatch($pattern, $file->getFilename())) {
                $files[substr($file->getPathname(), strlen($directory) + 1)] = $file->getPathname();
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Get the digest of a directory entry, which holds the directory and its pattern.
     */
    private function directoryEntryDigest(string $entry, bool $followLinks): string
    {
        // The pattern follows the directory, so resolving the entry resolves the directory.
        [$directory, $pattern] = explode("\t", $this->absolutePath($entry), 2);

        return self::directoryDigest($directory, $pattern, $followLinks);
    }

    /**
     * Get the key entries of directories mapped to their patterns.
     *
     * @param array<string, string> $directories
     * @return list<string>
     */
    private function directoryEntries(array $directories): array
    {
        $entries = [];

        foreach ($directories as $directory => $pattern) {
            $entries[] = $this->relativePath($directory) . "\t" . $pattern;
        }

        return $entries;
    }

    /**
     * Get a path relative to the working directory, or unchanged when it is outside it.
     */
    private function relativePath(string $path): string
    {
        return str_starts_with($path, $this->workingDirectory . '/') ? substr($path, strlen($this->workingDirectory) + 1) : $path;
    }

    /**
     * Get the absolute path of a key path.
     */
    private function absolutePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : $this->workingDirectory . '/' . $path;
    }

    /**
     * Get the typed entries of a key.
     *
     * @return list<array{self::DIRECTORY|self::FILE|self::LINKED_DIRECTORY|self::LISTING|self::PRESENCE|self::VARIABLE|self::VARIABLE_PATTERN, string}>
     */
    private function entries(string $key): array
    {
        if ($key === '') {
            return [];
        }

        $entries = [];

        foreach (explode("\n", $key) as $line) {
            /** @var array{self::DIRECTORY|self::FILE|self::LINKED_DIRECTORY|self::LISTING|self::PRESENCE|self::VARIABLE|self::VARIABLE_PATTERN, string} $entry createKey() writes each line as a type and an entry */
            $entry = explode("\t", $line, 2);
            $entries[] = $entry;
        }

        return $entries;
    }
}
