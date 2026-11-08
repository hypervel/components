<?php

declare(strict_types=1);

use Hypervel\Auth\RequestGuard;
use Hypervel\Auth\SessionGuard;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Filesystem\FilesystemAdapter;
use Hypervel\Redis\RedisConnection;
use Hypervel\Routing\ResponseFactory;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\Cache;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\Facades\Storage;
use Hypervel\Support\Optional;
use Hypervel\Support\Str;
use Hypervel\Tests\PHPStan\Fixtures\Macros\InvokableMacro;
use Hypervel\Tests\PHPStan\Fixtures\Macros\MacroHost;
use Hypervel\Tests\PHPStan\Fixtures\Managers\BoundExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ChildExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\CsvDriver;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ExtendedExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\JsonDriver;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ResolvedChildDriverManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\ResolvedExportManager;
use Hypervel\Tests\PHPStan\Fixtures\Managers\TabbedCsvDriver;
use Hypervel\Tests\PHPStan\Fixtures\Managers\WrappedCreatorManager;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter as LocalFlysystemAdapter;

Builder::macro('globalCustomMacro', static function (string $arg = 'foobar', int $b = 5): string {
    return $arg;
});

Builder::macro('getColumns', static function (): string {
    return 'macro';
});

Builder::macro('whereGlobalMacro', static function (): string {
    return 'macro';
});

QueryBuilder::macro('globalCustomDatabaseQueryMacro', static function (string $arg = 'foobar', int $b = 5): string {
    return $arg;
});

QueryBuilder::macro('whereQueryMacro', static function (): string {
    return 'macro';
});

Route::macro('facadeMacro', static function (): int {
    return 5;
});

SessionGuard::macro('sessionGuardMacro', static function (): int {
    return 5;
});

RequestGuard::macro('requestGuardMacro', static function (): int {
    return 5;
});

// Hypervel: a guard macro reaches AuthManager's calls only when every configured guard registers it.
SessionGuard::macro('guardMacro', static fn (): int => 5);
RequestGuard::macro('guardMacro', static fn (): int => 5);

Str::macro('trimMacro', 'trim');
Str::macro('asciiAliasMacro', Str::class . '::ascii');

Cache::macro('rememberIf', static fn ($cond, $key, $ttl, $callback): mixed => $cond ? Cache::remember($key, $ttl, $callback) : $callback());

// Hypervel: shares a held object's method name in the Optional fixture, where the macro must win.
Optional::macro('optionalMacro', static fn (): int => 1);

// Hypervel: Macroable also accepts invokable objects, and passes every macro its argument values.
MacroHost::macro('invokableMacro', new InvokableMacro);
MacroHost::macro('referenceMacro', static function (int &$value): void {
});

// Hypervel: only Request's validation macros throw ValidationException.
MacroHost::macro('validate', static fn (): bool => true);

// Hypervel: the documented response()->caps() macro, registered on the bound ResponseFactory.
ResponseFactory::macro('caps', static fn (string $value): string => strtoupper($value));

// Hypervel: manager creators registered at boot, and a driver created before its creator was replaced.
app()->singleton(ExtendedExportManager::class);
app(ExtendedExportManager::class)->extend('csv', static fn (): JsonDriver => new JsonDriver);

app()->singleton(WrappedCreatorManager::class);
app(WrappedCreatorManager::class)->extend('csv', static fn (): CsvDriver => new CsvDriver);

app()->singleton(ResolvedExportManager::class);
app(ResolvedExportManager::class)->driver();
app(ResolvedExportManager::class)->extend('csv', static fn (): JsonDriver => new JsonDriver);

app()->singleton(ResolvedChildDriverManager::class);
app(ResolvedChildDriverManager::class)->driver();
app(ResolvedChildDriverManager::class)->extend('csv', static fn (): TabbedCsvDriver => new TabbedCsvDriver);

app()->singleton(BoundExportManager::class, ChildExportManager::class);

// Hypervel: Redis connection macros, one under a command's lowercase name, which runs instead of the command.
RedisConnection::macro('zrank', static fn (): string => 'macro');
RedisConnection::macro('customCommand', static fn (): int => 1);

// Hypervel: filesystem creators for the storage disk fixtures, and a configured local
// disk replaced with another filesystem, which disk() then returns instead.
Storage::extend('custom', static fn (): FilesystemAdapter => Storage::createLocalDriver(['root' => sys_get_temp_dir()]));
Storage::extend('pooled-custom', static fn (): FilesystemAdapter => Storage::createLocalDriver(['root' => sys_get_temp_dir()]), poolable: true);

$replacedDiskAdapter = new LocalFlysystemAdapter(sys_get_temp_dir());
Storage::set('replaced', new FilesystemAdapter(new Flysystem($replacedDiskAdapter), $replacedDiskAdapter));

class CustomCollectionMacro
{
    /**
     * Register the collection macros.
     */
    public function registerMacro(): void
    {
        Collection::macro('customCollectionMacro', [$this, 'customMacro']);
        Collection::macro('customCollectionMacroString', [self::class, 'customMacroString']);
    }

    /**
     * Run the object-callable macro.
     */
    public function customMacro(): string
    {
        return 'customMacro';
    }

    /**
     * Run the class-string-callable macro.
     *
     * Static, unlike upstream: Macroable::macro() natively requires a callable, and a
     * class-string callable can only name a static method.
     */
    public static function customMacroString(): string
    {
        return 'customMacroString';
    }
}

(new CustomCollectionMacro)->registerMacro();

require_once __DIR__ . '/enum-definition.php';
