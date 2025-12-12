<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hyperf\Stringable\Str;
use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;
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

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        $tag = $ctx->prefixed('expire-' . Str::random(8));
        $key = $ctx->prefixed('expire:' . Str::random(8));

        // Put with 1 second TTL
        $ctx->cache->tags([$tag])->put($key, 'val', 1);

        $this->output?->writeln('  <fg=gray>Waiting 2 seconds for expiration...</>');
        sleep(2);

        if ($ctx->isAnyMode()) {
            // Any mode: direct get works
            $result->assert(
                $ctx->cache->get($key) === null,
                'Item expired after TTL'
            );
            $this->testAnyModeExpiration($ctx, $result, $tag, $key);
        } else {
            // All mode: must use tagged get
            $result->assert(
                $ctx->cache->tags([$tag])->get($key) === null,
                'Item expired after TTL'
            );
            $this->testAllModeExpiration($ctx, $result, $tag, $key);
        }

        return $result;
    }

    private function testAnyModeExpiration(
        DoctorContext $ctx,
        CheckResult $result,
        string $tag,
        string $key,
    ): void {
        // Check hash field cleanup
        $connection = $ctx->store->connection();
        $tagKey = $ctx->tagHashKey($tag);

        $result->assert(
            ! $connection->hExists($tagKey, $key),
            'Tag hash field expired (HEXPIRE cleanup)'
        );
    }

    private function testAllModeExpiration(
        DoctorContext $ctx,
        CheckResult $result,
        string $tag,
        string $key,
    ): void {
        // In all mode, the ZSET entry remains until flushStale() is called
        // The cache key has expired (Redis TTL), but the ZSET entry is stale
        $tagSetKey = $ctx->tagHashKey($tag);

        // Compute the namespaced key using central source of truth
        $namespacedKey = $ctx->namespacedKey([$tag], $key);

        // Check ZSET entry exists (stale but present)
        $score = $ctx->redis->zScore($tagSetKey, $namespacedKey);
        $staleEntryExists = $score !== false;

        $result->assert(
            $staleEntryExists,
            'Stale ZSET entry exists after cache key expired (before cleanup)'
        );

        // Run cleanup to remove stale entries
        $ctx->cache->tags([$tag])->flushStale();

        // Now the ZSET entry should be gone
        $scoreAfterCleanup = $ctx->redis->zScore($tagSetKey, $namespacedKey);
        $result->assert(
            $scoreAfterCleanup === false,
            'ZSET entry removed after flushStale() cleanup'
        );
    }
}
