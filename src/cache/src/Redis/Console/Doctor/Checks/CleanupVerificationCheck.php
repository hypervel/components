<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;

/**
 * Verifies that cleanup properly removes all test data.
 *
 * This check runs after cleanup to ensure no test keys remain in Redis.
 */
final class CleanupVerificationCheck implements CheckInterface
{
    /**
     * Get the human-readable name of this check.
     */
    public function name(): string
    {
        return 'Cleanup Verification';
    }

    /**
     * Run the check and return results.
     */
    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult;

        $testPrefix = $context->getTestPrefix();
        $remainingKeys = $this->findTestKeys($context, $testPrefix);

        $result->assert(
            empty($remainingKeys),
            empty($remainingKeys)
                ? 'All test data cleaned up successfully'
                : 'Cleanup incomplete - ' . count($remainingKeys) . ' test key(s) remain: ' . implode(', ', array_slice($remainingKeys, 0, 5))
        );

        // Any mode: verify tag registry has no test entries
        if ($context->isAnyMode()) {
            $registryOrphans = $this->findRegistryOrphans($context, $testPrefix);
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
    private function findTestKeys(DoctorContext $context, string $testPrefix): array
    {
        $remainingKeys = [];

        // Get patterns to check (includes both mode patterns for comprehensive verification)
        $patterns = array_merge(
            $context->getCacheValuePatterns($testPrefix),
            $context->getTagStoragePatterns($testPrefix),
        );

        foreach ($patterns as $pattern) {
            foreach ($context->redis->safeScan($pattern, 100) as $key) {
                $remainingKeys[] = $key;
            }
        }

        return array_unique($remainingKeys);
    }

    /**
     * Find any test entries remaining in the tag registry.
     *
     * @return array<string>
     */
    private function findRegistryOrphans(DoctorContext $context, string $testPrefix): array
    {
        $registryKey = $context->store->getContext()->registryKey();
        $members = $context->redis->zRange($registryKey, 0, -1);

        if (! is_array($members)) {
            return [];
        }

        return array_filter(
            $members,
            fn (string $member): bool => str_starts_with($member, $testPrefix)
        );
    }
}
