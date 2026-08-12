<?php

declare(strict_types=1);

namespace Hypervel\Testing\Concerns;

use Closure;
use Hypervel\Contracts\Console\Kernel;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Foundation\Application;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\ParallelTesting;
use Hypervel\Testing\ParallelConsoleOutput;
use ParaTest\Options;
use ParaTest\RunnerInterface;
use ParaTest\WrapperRunner\WrapperRunner;
use PHPUnit\TextUI\Configuration\PhpHandler;
use RuntimeException;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

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
     * The original test runner.
     */
    protected RunnerInterface $runner;

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
            return new WrapperRunner($options, $output);
        };

        $this->runner = $runnerResolver($options, $output);
    }

    /**
     * Set the application resolver callback.
     *
     * Tests only. The resolver persists in a static property for the worker
     * lifetime and affects every subsequent parallel test runner instance.
     */
    public static function resolveApplicationUsing(?Closure $resolver): void
    {
        static::$applicationResolver = $resolver;
    }

    /**
     * Set the runner resolver callback.
     *
     * Tests only. The resolver persists in a static property for the worker
     * lifetime and affects every subsequent parallel test runner instance.
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
        (new PhpHandler)->handle($this->options->configuration->php());

        $attemptedTokens = [];
        $exception = null;
        $exitCode = RunnerInterface::EXCEPTION_EXIT;

        try {
            $this->forEachProcess(function () use (&$attemptedTokens): void {
                $attemptedTokens[] = (string) ParallelTesting::token();
                ParallelTesting::callSetUpProcessCallbacks();
            });

            $exitCode = $this->runner->run();
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        // Re-running the overridable setup loop cannot guarantee the same owned token set.
        foreach ($attemptedTokens as $token) {
            try {
                $this->forProcess($token, fn () => ParallelTesting::callTearDownProcessCallbacks());
            } catch (Throwable $throwable) {
                $exception ??= $throwable;
            }
        }

        if ($exception !== null) {
            throw $exception;
        }

        return $exitCode;
    }

    // ParaTest 7 returns the final exit code from run() and exposes no getExitCode() method.

    /**
     * Apply the given callback for each process.
     */
    protected function forEachProcess(callable $callback): void
    {
        Collection::range(1, $this->options->processes)->each(function ($token) use ($callback): void {
            $this->forProcess((string) $token, $callback);
        });
    }

    /**
     * Apply the given callback for one process.
     */
    protected function forProcess(string $token, callable $callback): void
    {
        $application = $this->createApplication();
        $exception = null;

        try {
            ParallelTesting::resolveTokenUsing(fn () => $token);

            $callback($application);
        } catch (Throwable $throwable) {
            $exception = $throwable;
        }

        ParallelTesting::resolveTokenUsing(null);

        try {
            $application->terminate();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        try {
            $application->flush();
        } catch (Throwable $throwable) {
            $exception ??= $throwable;
        }

        if ($exception !== null) {
            throw $exception;
        }
    }

    /**
     * Create the application.
     *
     * @throws RuntimeException
     */
    protected function createApplication(): ApplicationContract
    {
        $applicationResolver = static::$applicationResolver ?: function () {
            $path = Application::inferBasePath() . '/bootstrap/app.php';

            if (file_exists($path)) {
                $app = require $path;

                $app->make(Kernel::class)->bootstrap();

                return $app;
            }

            throw new RuntimeException('Parallel Runner unable to resolve application.');
        };

        return $applicationResolver();
    }
}
