<?php

declare(strict_types=1);

namespace Hypervel\Sentry\Profiling;

use ExcimerLog;
use ExcimerProfiler;
use Hypervel\Coroutine\Coroutine;
use Hypervel\Sentry\State\CoroutineRuntimeContextStorage;
use Sentry\Options;
use Sentry\Profiling\Profile;
use Sentry\Profiling\ProfilerInterface;

/**
 * @internal
 */
class CoroutineProfiler implements ProfilerInterface
{
    // Match the PHP SDK transaction profiler so profiles remain comparable across runtimes.
    private const float SAMPLE_PERIOD = 0.0101;

    private const int MAX_STACK_DEPTH = 128;

    private readonly Profile $profile;

    private ?ExcimerProfiler $profiler;

    private readonly ProfileSamples $samples;

    /**
     * Create a profiler for a single sampled transaction.
     */
    public function __construct(Options $options, CoroutineRuntimeContextStorage $storage)
    {
        $this->profile = new Profile($options);
        $this->profile->setStartTimeStamp(microtime(true));
        $this->profiler = new ExcimerProfiler;
        $this->profiler->setEventType(EXCIMER_REAL);
        $this->profiler->setPeriod(self::SAMPLE_PERIOD);
        $this->profiler->setMaxDepth(self::MAX_STACK_DEPTH);

        $samples = new ProfileSamples;
        $this->samples = $samples;
        $context = $storage->get();
        $coroutineId = Coroutine::id();

        // The flush runs in the sampled coroutine. Capturing this profiler would keep the native timer alive.
        $this->profiler->setFlushCallback(static function (ExcimerLog $log) use ($samples, $context, $storage, $coroutineId): void {
            $samples->record($log, $context === null ? Coroutine::id() === $coroutineId : $storage->get() === $context);
        }, 1);
    }

    /**
     * Start collecting stack samples.
     */
    public function start(): void
    {
        $this->profiler->start();
    }

    /**
     * Stop collecting samples and attach them to the SDK profile.
     */
    public function stop(): void
    {
        $this->profiler?->stop();
        $this->profiler = null;
        $this->profile->setExcimerLog($this->samples->finish());
    }

    /**
     * Get the transaction profile.
     */
    public function getProfile(): Profile
    {
        return $this->profile;
    }
}
