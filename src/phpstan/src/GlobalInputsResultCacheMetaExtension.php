<?php

declare(strict_types=1);

namespace Hypervel\PHPStan;

use Hypervel\PHPStan\Support\ConfigSourceResolver;
use Hypervel\PHPStan\Support\EnvironmentSnapshot;
use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;
use PHPStan\File\FileHelper;

use function hash;
use function implode;
use function sort;

/**
 * Invalidate the whole result cache when an input that every file can depend on changes.
 *
 * PHPStan computes this metadata in the main process before anything boots. The boot-identity
 * variables choose which application, autoloaders and registrations exist, not just a value
 * inside one, and PHPStan rechecks the stubs it recorded but not a changed stub list. A config
 * file in a directory that a configured glob starts matching has no tracked path either.
 */
#[AutowiredService]
class GlobalInputsResultCacheMetaExtension implements ResultCacheMetaExtension // @phpstan-ignore class.implementsDeprecatedInterface (Value and file tracking are per file and cannot see a new stub or another booted application; only this metadata invalidates every file.)
{
    /**
     * The environment variables that select the booted application.
     */
    private const array BOOT_VARIABLES = ['APP_ENV', 'APP_BASE_PATH', 'COMPOSER_VENDOR_DIR', 'APP_RUNNING_IN_CONSOLE'];

    /**
     * The patterns of the environment variables that select the booted application.
     */
    private const array BOOT_VARIABLE_PATTERNS = ['APP_*_CACHE', 'TESTBENCH_*'];

    /**
     * Create a new extension instance.
     *
     * @param list<non-empty-string> $configuredDirectories
     */
    public function __construct(
        private HypervelStubFilesExtension $stubFilesExtension,
        private FileHelper $fileHelper,
        #[AutowiredParameter(ref: '%configDirectories%')]
        private array $configuredDirectories,
    ) {
    }

    /**
     * Get the metadata key.
     */
    public function getKey(): string
    {
        return 'hypervel.globalInputs';
    }

    /**
     * Get the hash of the stub list, the configured config directories and the boot-identity variables.
     */
    public function getHash(): string
    {
        $stubFiles = $this->stubFilesExtension->getFiles();
        sort($stubFiles);

        return hash('xxh128', implode("\0", [
            implode("\n", $stubFiles),
            implode("\n", ConfigSourceResolver::expandConfiguredDirectories($this->fileHelper, $this->configuredDirectories)),
            EnvironmentSnapshot::digest(self::BOOT_VARIABLES, self::BOOT_VARIABLE_PATTERNS),
        ]));
    }
}
