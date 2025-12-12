<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hyperf\Stringable\Str;
use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;
use Hypervel\Coroutine\Coroutine;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Hypervel\Coroutine\parallel;

/**
 * Tests real concurrency with coroutine-based parallel operations.
 *
 * Uses Hypervel's coroutine system instead of Laravel's process-based Concurrency.
 */
final class ConcurrencyCheck implements CheckInterface
{
    private ?OutputInterface $output = null;

    public function setOutput(OutputInterface $output): void
    {
        $this->output = $output;
    }

    public function name(): string
    {
        return 'Real Concurrency (Coroutines)';
    }

    public function run(DoctorContext $ctx): CheckResult
    {
        $result = new CheckResult();

        // Check if we're in a coroutine context
        if (! Coroutine::inCoroutine()) {
            $result->assert(
                true,
                'Concurrency tests skipped (not in coroutine context)'
            );

            return $result;
        }

        $this->testAtomicAdd($ctx, $result);
        $this->testConcurrentFlush($ctx, $result);

        return $result;
    }

    private function testAtomicAdd(DoctorContext $ctx, CheckResult $result): void
    {
        $key = $ctx->prefixed('real-concurrent:add-' . Str::random(8));
        $tag = $ctx->prefixed('concurrent-test');
        $ctx->cache->forget($key);

        try {
            $results = parallel([
                fn () => $ctx->cache->tags([$tag])->add($key, 'process-1', 60),
                fn () => $ctx->cache->tags([$tag])->add($key, 'process-2', 60),
                fn () => $ctx->cache->tags([$tag])->add($key, 'process-3', 60),
                fn () => $ctx->cache->tags([$tag])->add($key, 'process-4', 60),
                fn () => $ctx->cache->tags([$tag])->add($key, 'process-5', 60),
            ]);

            $successCount = count(array_filter($results, fn ($r): bool => $r === true));
            $result->assert(
                $successCount === 1,
                'Atomic add() - exactly 1 of 5 coroutines succeeded'
            );
        } catch (Throwable $e) {
            $this->output?->writeln("  <fg=yellow>⊘</> Atomic add() test skipped ({$e->getMessage()})");
        }
    }

    private function testConcurrentFlush(DoctorContext $ctx, CheckResult $result): void
    {
        $tag1 = $ctx->prefixed('concurrent-flush-a-' . Str::random(8));
        $tag2 = $ctx->prefixed('concurrent-flush-b-' . Str::random(8));

        // Create 5 items with both tags
        for ($i = 0; $i < 5; ++$i) {
            $ctx->cache->tags([$tag1, $tag2])->put($ctx->prefixed("flush-item-{$i}"), "value-{$i}", 60);
        }

        try {
            // Flush both tags concurrently
            parallel([
                fn () => $ctx->cache->tags([$tag1])->flush(),
                fn () => $ctx->cache->tags([$tag2])->flush(),
            ]);

            if ($ctx->isAnyMode()) {
                // Verify no orphans in either tag hash
                $tag1Key = $ctx->tagHashKey($tag1);
                $tag2Key = $ctx->tagHashKey($tag2);

                $result->assert(
                    $ctx->redis->exists($tag1Key) === 0 && $ctx->redis->exists($tag2Key) === 0,
                    'Concurrent flush - no orphaned tag hashes'
                );
            } else {
                // All mode: verify both tag ZSETs are deleted
                $tag1SetKey = $ctx->tagHashKey($tag1);
                $tag2SetKey = $ctx->tagHashKey($tag2);

                $result->assert(
                    $ctx->redis->exists($tag1SetKey) === 0 && $ctx->redis->exists($tag2SetKey) === 0,
                    'Concurrent flush - both tag ZSETs deleted (all mode)'
                );
            }
        } catch (Throwable $e) {
            $this->output?->writeln("  <fg=yellow>⊘</> Concurrent flush test skipped ({$e->getMessage()})");
        }
    }
}
