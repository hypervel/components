<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;
use Redis;

/**
 * Verifies that cleanup properly removes all test data.
 *
 * This check runs AFTER cleanup to ensure no test keys remain in Redis.
 * It catches regressions in cleanup logic that could leave orphaned test data.
 */
final class CleanupVerificationCheck implements CheckInterface
{
    public function name(): string
    {
        return 'Cleanup Verification';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        $testPrefix = $ctx->getTestPrefix();
        $remainingKeys = $this->findTestKeys($ctx, $testPrefix);

        $result->assert(
            empty($remainingKeys),
            empty($remainingKeys)
                ? 'All test data cleaned up successfully'
                : 'Cleanup incomplete - ' . count($remainingKeys) . ' test key(s) remain: ' . implode(', ', array_slice($remainingKeys, 0, 5))
        );

        // Any mode: verify tag registry has no test entries
        if ($ctx->isAnyMode()) {
            $registryOrphans = $this->findRegistryOrphans($ctx, $testPrefix);
            $result->assert(
                empty($registryOrphans),
                empty($registryOrphans)
                    ? 'Tag registry has no test entries'
                    : 'Tag registry has orphaned test entries: ' . implode(', ', array_slice($registryOrphans, 0, 5))
            );
        }

        return $result;
    }

    /**
     * Find any remaining test keys in Redis.
     *
     * @return array<string>
     */
    private function findTestKeys(DoctorContext $ctx, string $testPrefix): array
    {
        $remainingKeys = [];

        // Get patterns to check (includes both mode patterns for comprehensive verification)
        $patterns = array_merge(
            $ctx->getCacheValuePatterns($testPrefix),
            $ctx->getTagStoragePatterns($testPrefix),
        );

        // Get OPT_PREFIX for SCAN pattern
        $optPrefix = (string) $ctx->redis->getOption(Redis::OPT_PREFIX);

        foreach ($patterns as $pattern) {
            // SCAN requires the full pattern including OPT_PREFIX
            $scanPattern = $optPrefix . $pattern;
            $iterator = null;

            while (($keys = $ctx->redis->scan($iterator, $scanPattern, 100)) !== false) {
                foreach ($keys as $key) {
                    // Strip OPT_PREFIX from returned keys for display
                    $remainingKeys[] = $optPrefix ? substr($key, strlen($optPrefix)) : $key;
                }

                if ($iterator === 0) {
                    break;
                }
            }
        }

        return array_unique($remainingKeys);
    }

    /**
     * Find any test entries remaining in the tag registry.
     *
     * @return array<string>
     */
    private function findRegistryOrphans(DoctorContext $ctx, string $testPrefix): array
    {
        $registryKey = $ctx->store->getContext()->registryKey();
        $members = $ctx->redis->zRange($registryKey, 0, -1);

        if (! is_array($members)) {
            return [];
        }

        return array_filter(
            $members,
            fn ($m) => str_starts_with($m, $testPrefix)
        );
    }
}
