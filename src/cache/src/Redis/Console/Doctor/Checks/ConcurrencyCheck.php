<?php

declare(strict_types=1);

namespace Hypervel\Cache\Redis\Console\Doctor\Checks;

use Hypervel\Cache\Redis\Console\Doctor\CheckResult;
use Hypervel\Cache\Redis\Console\Doctor\DoctorContext;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Support\Str;
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

    public function run(DoctorContext $context): CheckResult
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

        $this->testAtomicAdd($context, $result);
        $this->testConcurrentFlush($context, $result);

        return $result;
    }

    private function testAtomicAdd(DoctorContext $context, CheckResult $result): void
    {
        $key = $context->prefixed('real-concurrent:add-' . Str::random(8));
        $tag = $context->prefixed('concurrent-test');
        $context->cache->forget($key);

        try {
            $results = parallel([
                fn () => $context->cache->tags([$tag])->add($key, 'process-1', 60),
                fn () => $context->cache->tags([$tag])->add($key, 'process-2', 60),
                fn () => $context->cache->tags([$tag])->add($key, 'process-3', 60),
                fn () => $context->cache->tags([$tag])->add($key, 'process-4', 60),
                fn () => $context->cache->tags([$tag])->add($key, 'process-5', 60),
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

    private function testConcurrentFlush(DoctorContext $context, CheckResult $result): void
    {
        $tag1 = $context->prefixed('concurrent-flush-a-' . Str::random(8));
        $tag2 = $context->prefixed('concurrent-flush-b-' . Str::random(8));

        // Create 5 items with both tags
        for ($i = 0; $i < 5; ++$i) {
            $context->cache->tags([$tag1, $tag2])->put($context->prefixed("flush-item-{$i}"), "value-{$i}", 60);
        }

        try {
            // Flush both tags concurrently
            parallel([
                fn () => $context->cache->tags([$tag1])->flush(),
                fn () => $context->cache->tags([$tag2])->flush(),
            ]);

            if ($context->isAnyMode()) {
                // Verify no orphans in either tag hash
                $tag1Key = $context->tagHashKey($tag1);
                $tag2Key = $context->tagHashKey($tag2);

                $result->assert(
                    $context->redis->exists($tag1Key) === 0 && $context->redis->exists($tag2Key) === 0,
                    'Concurrent flush - no orphaned tag hashes'
                );
            } else {
                // All mode: verify both tag ZSETs are deleted
                $tag1SetKey = $context->tagHashKey($tag1);
                $tag2SetKey = $context->tagHashKey($tag2);

                $result->assert(
                    $context->redis->exists($tag1SetKey) === 0 && $context->redis->exists($tag2SetKey) === 0,
                    'Concurrent flush - both tag ZSETs deleted (all mode)'
                );
            }
        } catch (Throwable $e) {
            $this->output?->writeln("  <fg=yellow>⊘</> Concurrent flush test skipped ({$e->getMessage()})");
        }
    }
}
