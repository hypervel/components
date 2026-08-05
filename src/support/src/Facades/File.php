<?php

declare(strict_types=1);

namespace Hypervel\Support\Facades;

use SensitiveParameter;

/**
 * @method static bool exists(string $path)
 * @method static bool missing(string $path)
 * @method static string get(string $path, bool $lock = false)
 * @method static mixed json(string $path, int $flags = 0, bool $lock = false)
 * @method static string sharedGet(string $path)
 * @method static mixed getRequire(string $path, array $data = [])
 * @method static mixed requireOnce(string $path, array $data = [])
 * @method static \Hypervel\Support\LazyCollection lines(string $path)
 * @method static string|false hash(string $path, string $algorithm = 'xxh128')
 * @method static int|false put(string $path, resource|string $contents, bool $lock = false)
 * @method static void replaceInFile(array|string $search, array|string $replace, string $path)
 * @method static int|false prepend(string $path, string $data)
 * @method static int|false append(string $path, string $data, bool $lock = false)
 * @method static string|bool chmod(string $path, int|null $mode = null)
 * @method static bool delete(array|string $paths)
 * @method static bool move(string $path, string $target)
 * @method static bool copy(string $path, string $target)
 * @method static bool|null link(string $target, string $link)
 * @method static void relativeLink(string $target, string $link)
 * @method static string name(string $path)
 * @method static string basename(string $path)
 * @method static string dirname(string $path)
 * @method static string extension(string $path)
 * @method static string|null guessExtension(string $path)
 * @method static string|false type(string $path)
 * @method static string|false mimeType(string $path)
 * @method static int|false size(string $path)
 * @method static int|false lastModified(string $path)
 * @method static bool isDirectory(string $directory)
 * @method static bool isEmptyDirectory(string $directory, bool $ignoreDotFiles = false)
 * @method static bool isReadable(string $path)
 * @method static bool isWritable(string $path)
 * @method static bool hasSameHash(string $firstFile, string $secondFile)
 * @method static bool isFile(string $file)
 * @method static array|false glob(string $pattern, int $flags = 0)
 * @method static \SplFileInfo[] files(array|string $directory, bool $hidden = false, array|string|int $depth = 0)
 * @method static \SplFileInfo[] allFiles(array|string $directory, bool $hidden = false)
 * @method static array directories(array|string $directory, array|string|int $depth = 0)
 * @method static array allDirectories(array|string $directory)
 * @method static void ensureDirectoryExists(string $path, int $mode = 0755, bool $recursive = true)
 * @method static bool makeDirectory(string $path, int $mode = 0755, bool $recursive = false, bool $force = false)
 * @method static bool moveDirectory(string $from, string $to, bool $overwrite = false)
 * @method static bool copyDirectory(string $directory, string $destination, int|null $options = null)
 * @method static bool deleteDirectory(string $directory, bool $preserve = false)
 * @method static bool deleteDirectories(string $directory)
 * @method static bool cleanDirectory(string $directory)
 * @method static void clearStatCache(string $path)
 * @method static void flushState()
 * @method static \Hypervel\Filesystem\Filesystem|mixed when(null|\Closure|mixed $value = null, null|callable $callback = null, null|callable $default = null)
 * @method static \Hypervel\Filesystem\Filesystem|mixed unless(null|\Closure|mixed $value = null, null|callable $callback = null, null|callable $default = null)
 * @method static void macro(string $name, callable|object $macro)
 * @method static void mixin(object $mixin, bool $replace = true)
 * @method static bool hasMacro(string $name)
 * @method static void flushMacros()
 *
 * @see \Hypervel\Filesystem\Filesystem
 */
class File extends Facade
{
    /**
     * Write the contents of a file, replacing it atomically if it already exists.
     */
    public static function replace(string $path, #[SensitiveParameter] string $content, ?int $mode = null): void
    {
        static::getFacadeRoot()->replace($path, $content, $mode);
    }

    protected static function getFacadeAccessor(): string
    {
        return 'files';
    }
}
