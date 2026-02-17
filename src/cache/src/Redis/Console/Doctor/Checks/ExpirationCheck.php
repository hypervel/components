<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;
use Hypervel\Support\Str;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Tests TTL expiration behavior.
 *
 * Basic expiration is mode-agnostic, but hash field cleanup verification
 * is any mode specific.
 */
final class ExpirationCheck implements CheckInterface
{
    private ?OutputInterface $output = null;

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function name(): string
    {
        return 'Expiration Tests';
    }

    public function run(DoctorContext $context): CheckResult
    {
        $result = new CheckResult();

        $tag = $context->prefixed('expire-' . Str::random(8));
        $key = $context->prefixed('expire:' . Str::random(8));

        // Put with 1 second TTL
        $context->cache->tags([$tag])->put($key, 'val', 1);

        $this->output?->writeln('  <fg=gray>Waiting 2 seconds for expiration...</>');
        sleep(2);

        if ($context->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $context->cache->get($key) === null,
                'Item expired after TTL'
            );
            $this->testAnyModeExpiration($context, $result, $tag, $key);
        } else {
            // All mode: must use tagged get
            $result->assert(
                $context->cache->tags([$tag])->get($key) === null,
                'Item expired after TTL'
            );
            $this->testAllModeExpiration($context, $result, $tag, $key);
        }

        return $result;
    }

    private function testAnyModeExpiration(
        DoctorContext $context,
        CheckResult $result,
        string $tag,
        string $key,
    ): void {
        // Check hash field cleanup
        $connection = $context->store->connection();
        $tagKey = $context->tagHashKey($tag);

        $result->assert(
            ! $connection->hExists($tagKey, $key),
            'Tag hash field expired (HEXPIRE cleanup)'
        );
    }

    private function testAllModeExpiration(
        DoctorContext $context,
        CheckResult $result,
        string $tag,
        string $key,
    ): void {
        // In all mode, the ZSET entry remains until flushStale() is called
        // The cache key has expired (Redis TTL), but the ZSET entry is stale
        $tagSetKey = $context->tagHashKey($tag);

        // Compute the namespaced key using central source of truth
        $namespacedKey = $context->namespacedKey([$tag], $key);

        // Check ZSET entry exists (stale but present)
        $score = $context->redis->zScore($tagSetKey, $namespacedKey);
        $staleEntryExists = $score !== false;

        $result->assert(
            $staleEntryExists,
            'Stale ZSET entry exists after cache key expired (before cleanup)'
        );

        // Run cleanup to remove stale entries
        /** @var \Hypervel\Cache\Redis\AllTaggedCache $taggedCache */
        $taggedCache = $context->cache->tags([$tag]);
        $taggedCache->flushStale();

        // Now the ZSET entry should be gone
        $scoreAfterCleanup = $context->redis->zScore($tagSetKey, $namespacedKey);
        $result->assert(
            $scoreAfterCleanup === false,
            'ZSET entry removed after flushStale() cleanup'
        );
    }
}
