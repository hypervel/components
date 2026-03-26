<?php

declare(strict_types=1);

namespace Hypervel\Filesystem;

use ErrorException;
use FilesystemIterator;
use Hypervel\Contracts\Filesystem\FileNotFoundException;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Coroutine\Locker;
use Hypervel\Support\LazyCollection;
use Hypervel\Support\Traits\Conditionable;
use Hypervel\Support\Traits\Macroable;
use RuntimeException;
use SplFileObject;
use Symfony\Component\Filesystem\Filesystem as SymfonyFilesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Mime\MimeTypes;

class Filesystem
{
    use Conditionable;
    use Macroable;

    /**
     * Determine if a file or directory exists.
     */
    public function exists(string $path): bool
    {
        return file_exists($path);
    }

    /**
     * Determine if a file or directory is missing.
     */
    public function missing(string $path): bool
    {
        return ! $this->exists($path);
    }

    /**
     * Get the contents of a file.
     *
     * @throws FileNotFoundException
     */
    public function get(string $path, bool $lock = false): string
    {
        if ($this->isFile($path)) {
            return $lock ? $this->sharedGet($path) : file_get_contents($path);
        }

        throw new FileNotFoundException("File does not exist at path {$path}.");
    }

    /**
     * Get the contents of a file as decoded JSON.
     *
     * @throws FileNotFoundException
     */
    public function json(string $path, int $flags = 0, bool $lock = false): array
    {
        return json_decode($this->get($path, $lock), true, 512, $flags);
    }

    /**
     * Get contents of a file with shared access.
     */
    public function sharedGet(string $path): string
    {
        return $this->atomic($path, function ($path) {
            $contents = '';
            $handle = fopen($path, 'rb');
            if ($handle) {
                $wouldBlock = false;
                flock($handle, LOCK_SH | LOCK_NB, $wouldBlock);
                while ($wouldBlock) {
                    usleep(1000);
                    flock($handle, LOCK_SH | LOCK_NB, $wouldBlock);
                }
                try {
                    clearstatcache(true, $path);
                    $contents = stream_get_contents($handle);
                } finally {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                }
            }
            return $contents;
        });
    }

    /**
     * Get the returned value of a file.
     *
     * @throws FileNotFoundException
     */
    public function getRequire(string $path, array $data = []): mixed
    {
        if ($this->isFile($path)) {
            $__path = $path;
            $__data = $data;

            return (static function () use ($__path, $__data) {
                extract($__data, EXTR_SKIP);

                return require $__path;
            })();
        }

        throw new FileNotFoundException("File does not exist at path {$path}.");
    }

    /**
     * Require the given file once.
     *
     * @throws FileNotFoundException
     */
    public function requireOnce(string $path, array $data = []): mixed
    {
        if ($this->isFile($path)) {
            $__path = $path;
            $__data = $data;

            return (static function () use ($__path, $__data) {
                extract($__data, EXTR_SKIP);

                return require_once $__path;
            })();
        }

        throw new FileNotFoundException("File does not exist at path {$path}.");
    }

    /**
     * Get the contents of a file one line at a time.
     *
     * @throws FileNotFoundException
     */
    public function lines(string $path): LazyCollection
    {
        if (! $this->isFile($path)) {
            throw new FileNotFoundException(
                "File does not exist at path {$path}."
            );
        }

        return new LazyCollection(function () use ($path) {
            $file = new SplFileObject($path);

            $file->setFlags(SplFileObject::DROP_NEW_LINE);

            while (! $file->eof()) {
                yield $file->fgets();
            }
        });
    }

    /**
     * Get the hash of the file at the given path.
     */
    public function hash(string $path, string $algorithm = 'md5'): string|false
    {
        return hash_file($algorithm, $path);
    }

    /**
     * Write the contents of a file.
     *
     * @param resource|string $contents
     * @return bool|int
     */
    public function put(string $path, $contents, bool $lock = false)
    {
        if ($lock) {
            return $this->atomic($path, function ($path) use ($contents) {
                $handle = fopen($path, 'w+');
                if ($handle) {
                    $wouldBlock = false;
                    flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
                    while ($wouldBlock) {
                        usleep(1000);
                        flock($handle, LOCK_EX | LOCK_NB, $wouldBlock);
                    }
                    try {
                        return fwrite($handle, $contents);
                    } finally {
                        flock($handle, LOCK_UN);
                        fclose($handle);
                    }
                }
            });
        }
        return file_put_contents($path, $contents);
    }

    /**
     * Write the contents of a file, replacing it atomically if it already exists.
     */
    public function replace(string $path, string $content, ?int $mode = null): void
    {
        // If the path already exists and is a symlink, get the real path...
        clearstatcache(true, $path);

        $path = realpath($path) ?: $path;

        $tempPath = tempnam(dirname($path), basename($path));

        // Fix permissions of tempPath because `tempnam()` creates it with permissions set to 0600...
        if (! is_null($mode)) {
            chmod($tempPath, $mode);
        } else {
            chmod($tempPath, 0777 - umask());
        }

        file_put_contents($tempPath, $content);

        rename($tempPath, $path);
    }

    /**
     * Replace a given string within a given file.
     */
    public function replaceInFile(array|string $search, array|string $replace, string $path): void
    {
        file_put_contents($path, str_replace($search, $replace, file_get_contents($path)));
    }

    /**
     * Prepend to a file.
     */
    public function prepend(string $path, string $data): int
    {
        if ($this->exists($path)) {
            return $this->put($path, $data . $this->get($path));
        }

        return $this->put($path, $data);
    }

    /**
     * Append to a file.
     */
    public function append(string $path, string $data, bool $lock = false): int
    {
        return file_put_contents($path, $data, FILE_APPEND | ($lock ? LOCK_EX : 0));
    }

    /**
     * Get or set UNIX mode of a file or directory.
     */
    public function chmod(string $path, ?int $mode = null): string|bool
    {
        if ($mode) {
            return chmod($path, $mode);
        }

        return substr(sprintf('%o', fileperms($path)), -4);
    }

    /**
     * Delete the file at a given path.
     *
     * @param array|string $paths
     */
    public function delete($paths): bool
    {
        $paths = is_array($paths) ? $paths : func_get_args();

        $success = true;

        foreach ($paths as $path) {
            try {
                if (@unlink($path)) {
                    clearstatcache(false, $path);
                } else {
                    $success = false;
                }
            } catch (ErrorException) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Move a file to a new location.
     */
    public function move(string $path, string $target): bool
    {
        return rename($path, $target);
    }

    /**
     * Copy a file to a new location.
     */
    public function copy(string $path, string $target): bool
    {
        return copy($path, $target);
    }

    /**
     * Create a symlink to the target file or directory. On Windows, a hard link is created if the target is a file.
     */
    public function link(string $target, string $link): ?bool
    {
        if (! windows_os()) {
            if (function_exists('symlink')) {
                return symlink($target, $link);
            }
            exec('ln -s ' . escapeshellarg($target) . ' ' . escapeshellarg($link), $output, $exitCode);

            return $exitCode === 0;
        }

        $mode = $this->isDirectory($target) ? 'J' : 'H';

        exec("mklink /{$mode} " . escapeshellarg($link) . ' ' . escapeshellarg($target));

        return null;
    }

    /**
     * Create a relative symlink to the target file or directory.
     *
     * @throws RuntimeException
     */
    public function relativeLink(string $target, string $link): void
    {
        if (! class_exists(SymfonyFilesystem::class)) {
            throw new RuntimeException(
                'To enable support for relative links, please install the symfony/filesystem package.'
            );
        }

        $relativeTarget = (new SymfonyFilesystem())->makePathRelative($target, dirname($link));

        $this->link($this->isFile($target) ? rtrim($relativeTarget, '/') : $relativeTarget, $link);
    }

    /**
     * Extract the file name from a file path.
     */
    public function name(string $path): string
    {
        return pathinfo($path, PATHINFO_FILENAME);
    }

    /**
     * Extract the trailing name component from a file path.
     */
    public function basename(string $path): string
    {
        return pathinfo($path, PATHINFO_BASENAME);
    }

    /**
     * Extract the parent directory from a file path.
     */
    public function dirname(string $path): string
    {
        return pathinfo($path, PATHINFO_DIRNAME);
    }

    /**
     * Extract the file extension from a file path.
     */
    public function extension(string $path): string
    {
        return pathinfo($path, PATHINFO_EXTENSION);
    }

    /**
     * Guess the file extension from the MIME type of a given file.
     *
     * @throws RuntimeException
     */
    public function guessExtension(string $path): ?string
    {
        if (! class_exists(MimeTypes::class)) {
            throw new RuntimeException(
                'To enable support for guessing extensions, please install the symfony/mime package.'
            );
        }

        return (new MimeTypes())->getExtensions($this->mimeType($path))[0] ?? null;
    }

    /**
     * Get the file type of a given file.
     */
    public function type(string $path): string
    {
        return filetype($path);
    }

    /**
     * Get the MIME type of a given file.
     */
    public function mimeType(string $path): string|false
    {
        return finfo_file(finfo_open(FILEINFO_MIME_TYPE), $path);
    }

    /**
     * Get the file size of a given file.
     */
    public function size(string $path): int
    {
        return filesize($path);
    }

    /**
     * Get the file's last modification time.
     */
    public function lastModified(string $path): int
    {
        return filemtime($path);
    }

    /**
     * Determine if the given path is a directory.
     */
    public function isDirectory(string $directory): bool
    {
        return is_dir($directory);
    }

    /**
     * Determine if the given path is a directory that does not contain any other files or directories.
     */
    public function isEmptyDirectory(string $directory, bool $ignoreDotFiles = false): bool
    {
        return ! Finder::create()->ignoreDotFiles($ignoreDotFiles)->in($directory)->depth(0)->hasResults();
    }

    /**
     * Determine if the given path is readable.
     */
    public function isReadable(string $path): bool
    {
        return is_readable($path);
    }

    /**
     * Determine if the given path is writable.
     */
    public function isWritable(string $path): bool
    {
        return is_writable($path);
    }

    /**
     * Determine if two files are the same by comparing their hashes.
     */
    public function hasSameHash(string $firstFile, string $secondFile): bool
    {
        $hash = @hash_file('xxh128', $firstFile);

        return $hash && hash_equals($hash, (string) @hash_file('xxh128', $secondFile));
    }

    /**
     * Determine if the given path is a file.
     */
    public function isFile(string $file): bool
    {
        return is_file($file);
    }

    /**
     * Find path names matching a given pattern.
     */
    public function glob(string $pattern, int $flags = 0): array
    {
        return glob($pattern, $flags);
    }

    /**
     * Get an array of all files in a directory.
     *
     * @return SplFileInfo[]
     */
    public function files(array|string $directory, bool $hidden = false, array|string|int $depth = 0): array
    {
        return iterator_to_array(
            Finder::create()->files()->ignoreDotFiles(! $hidden)->in($directory)->depth($depth)->sortByName(),
            false
        );
    }

    /**
     * Get all of the files from the given directory (recursive).
     *
     * @return SplFileInfo[]
     */
    public function allFiles(array|string $directory, bool $hidden = false): array
    {
        return $this->files($directory, $hidden, []);
    }

    /**
     * Get all of the directories within a given directory.
     */
    public function directories(array|string $directory, array|string|int $depth = 0): array
    {
        $directories = [];

        foreach (Finder::create()->in($directory)->directories()->depth($depth)->sortByName() as $dir) {
            $directories[] = $dir->getPathname();
        }

        return $directories;
    }

    /**
     * Get all the directories within a given directory (recursive).
     */
    public function allDirectories(array|string $directory): array
    {
        return $this->directories($directory, []);
    }

    /**
     * Ensure a directory exists.
     */
    public function ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true): void
    {
        if (! $this->isDirectory($path)) {
            $this->makeDirectory($path, $mode, $recursive);
        }
    }

    /**
     * Create a directory.
     */
    public function makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false): bool
    {
        if ($force) {
            return @mkdir($path, $mode, $recursive);
        }

        return mkdir($path, $mode, $recursive);
    }

    /**
     * Move a directory.
     */
    public function moveDirectory(string $from, string $to, bool $overwrite = false): bool
    {
        if ($overwrite && $this->isDirectory($to) && ! $this->deleteDirectory($to)) {
            return false;
        }

        return @rename($from, $to) === true;
    }

    /**
     * Copy a directory from one location to another.
     */
    public function copyDirectory(string $directory, string $destination, ?int $options = null): bool
    {
        if (! $this->isDirectory($directory)) {
            return false;
        }

        $options = $options ?: FilesystemIterator::SKIP_DOTS;

        // If the destination directory does not actually exist, we will go ahead and
        // create it recursively, which just gets the destination prepared to copy
        // the files over. Once we make the directory we'll proceed the copying.
        $this->ensureDirectoryExists($destination, 0777);

        $items = new FilesystemIterator($directory, $options);

        foreach ($items as $item) {
            // As we spin through items, we will check to see if the current file is actually
            // a directory or a file. When it is actually a directory we will need to call
            // back into this function recursively to keep copying these nested folders.
            $target = $destination . '/' . $item->getBasename();

            if ($item->isDir()) {
                $path = $item->getPathname();

                if (! $this->copyDirectory($path, $target, $options)) {
                    return false;
                }
            }

            // If the current items is just a regular file, we will just copy this to the new
            // location and keep looping. If for some reason the copy fails we'll bail out
            // and return false, so the developer is aware that the copy process failed.
            elseif (! $this->copy($item->getPathname(), $target)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Recursively delete a directory.
     *
     * The directory itself may be optionally preserved.
     */
    public function deleteDirectory(string $directory, bool $preserve = false): bool
    {
        if (! $this->isDirectory($directory)) {
            return false;
        }

        $items = new FilesystemIterator($directory);

        foreach ($items as $item) {
            // If the item is a directory, we can just recurse into the function and
            // delete that sub-directory otherwise we'll just delete the file and
            // keep iterating through each file until the directory is cleaned.
            if ($item->isDir() && ! $item->isLink()) {
                $this->deleteDirectory($item->getPathname());
            }

            // If the item is just a file, we can go ahead and delete it since we're
            // just looping through and waxing all of the files in this directory
            // and calling directories recursively, so we delete the real path.
            else {
                $this->delete($item->getPathname());
            }
        }

        unset($items);

        if (! $preserve) {
            @rmdir($directory);
        }

        return true;
    }

    /**
     * Remove all of the directories within a given directory.
     */
    public function deleteDirectories(string $directory): bool
    {
        $allDirectories = $this->directories($directory);

        if (! empty($allDirectories)) {
            foreach ($allDirectories as $directoryName) {
                $this->deleteDirectory($directoryName);
            }

            return true;
        }

        return false;
    }

    /**
     * Empty the specified directory of all files and folders.
     */
    public function cleanDirectory(string $directory): bool
    {
        return $this->deleteDirectory($directory, true);
    }

    /**
     * Clear file status cache.
     */
    public function clearStatCache(string $path): void
    {
        clearstatcache(true, $path);
    }

    /**
     * Execute a callback with coroutine-safe file locking.
     */
    protected function atomic(string $path, callable $callback): mixed
    {
        if (Coroutine::inCoroutine()) {
            try {
                while (! Locker::lock($path)) {
                    usleep(1000);
                }
                return $callback($path);
            } finally {
                Locker::unlock($path);
            }
        }

        return $callback($path);
    }
}
