<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Closure;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Testing\ParallelConsoleOutput;
use ParaTest\Options;
use PHPUnit\TextUI\Configuration\PhpHandler;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;

trait RunsInParallel
{
    /**
     * The application resolver callback.
     */
    protected static ?Closure $applicationResolver = null;

    /**
     * The runner resolver callback.
     */
    protected static ?Closure $runnerResolver = null;

    /**
     * The original test runner options.
     */
    protected Options $options;

    /**
     * The output instance.
     */
    protected OutputInterface $output;

    /**
     * The original test runner.
     */
    protected \ParaTest\RunnerInterface $runner;

    /**
     * Create a new test runner instance.
     */
    public function __construct(Options $options, OutputInterface $output)
    {
        $this->options = $options;

        if ($output instanceof ConsoleOutput) {
            $output = new ParallelConsoleOutput($output);
        }

        $runnerResolver = static::$runnerResolver ?: function (Options $options, OutputInterface $output) {
            return new \ParaTest\WrapperRunner\WrapperRunner($options, $output);
        };

        $this->runner = $runnerResolver($options, $output);
    }

    /**
     * Set the application resolver callback.
     */
    public static function resolveApplicationUsing(?Closure $resolver): void
    {
        static::$applicationResolver = $resolver;
    }

    /**
     * Set the runner resolver callback.
     */
    public static function resolveRunnerUsing(?Closure $resolver): void
    {
        static::$runnerResolver = $resolver;
    }

    /**
     * Run the test suite.
     */
    public function execute(): int
    {
        (new PhpHandler())->handle($this->options->configuration->php());

        $this->forEachProcess(function () {
            ParallelTesting::callSetUpProcessCallbacks();
        });

        try {
            $exitCode = $this->runner->run();
        } finally {
            $this->forEachProcess(function () {
                ParallelTesting::callTearDownProcessCallbacks();
            });
        }

        return $exitCode;
    }

    /**
     * Apply the given callback for each process.
     */
    protected function forEachProcess(callable $callback): void
    {
        Collection::range(1, $this->options->processes)->each(function ($token) use ($callback) {
            tap($this->createApplication(), function ($app) use ($callback, $token) {
                ParallelTesting::resolveTokenUsing(fn () => $token);

                $callback($app);
            })->flush();
        });
    }

    /**
     * Create the application.
     *
     * @throws RuntimeException
     */
    protected function createApplication(): \Hypervel\Contracts\Foundation\Application
    {
        $applicationResolver = static::$applicationResolver ?: function () {
            throw new RuntimeException('Parallel Runner unable to resolve application.');
        };

        return $applicationResolver();
    }
}
