<?php

declare(strict_types=1);

namespace Hypervel\PHPStan;

use PHPStan\Analyser\ResultCache\ResultCacheMetaExtension;
use PHPStan\DependencyInjection\AutowiredParameter;
use PHPStan\DependencyInjection\AutowiredService;

use function hash;
use function implode;
use function sort;

/**
 * Invalidate the result cache when an attribute-registered extension is added or removed.
 *
 * @TODO PHPStan 2.3.5 hashes the files of known extensions but not the list of attribute services,
 * so a newly added #[AutowiredService] extension leaves a warm cache's results stale. Remove this
 * class once the minimum supported PHPStan version records that list in its result-cache metadata.
 */
#[AutowiredService]
class AttributeServicesResultCacheMetaExtension implements ResultCacheMetaExtension // @phpstan-ignore class.implementsDeprecatedInterface (Value and file tracking are per file and cannot see a newly added extension; only this metadata invalidates every file.)
{
    /**
     * Create a new extension instance.
     *
     * @param list<string> $classes
     */
    public function __construct(
        #[AutowiredParameter(ref: '%attributeServicesClasses%')]
        private array $classes,
    ) {
    }

    /**
     * Get the metadata key.
     */
    public function getKey(): string
    {
        return 'hypervel.attributeServicesClasses';
    }

    /**
     * Get the hash of the attribute-registered service classes.
     */
    public function getHash(): string
    {
        $classes = $this->classes;
        sort($classes);

        return hash('xxh128', implode("\n", $classes));
    }
}
