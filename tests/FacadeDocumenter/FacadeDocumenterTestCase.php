<?php

declare(strict_types=1);

namespace Hypervel\Tests\FacadeDocumenter;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Testbench\TestCase;
use Symfony\Component\Process\Process;

abstract class FacadeDocumenterTestCase extends TestCase
{
    /**
     * Subprocess tests don't need coroutines.
     */
    protected bool $runTestsInCoroutine = false;

    protected Filesystem $filesystem;

    /** @var array<string, true> */
    protected array $fixtureRoots = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->filesystem = new Filesystem;
        $this->fixtureRoots = [];
    }

    protected function tearDown(): void
    {
        try {
            foreach (array_keys($this->fixtureRoots) as $fixtureRoot) {
                if (is_dir($fixtureRoot)) {
                    $this->filesystem->deleteDirectory($fixtureRoot);
                } else {
                    $this->filesystem->delete($fixtureRoot);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    /**
     * Write a PHP fixture file under BASE_PATH/app/{relativePath} so it is
     * autoloadable as App\{NamespaceFromRelativePath}\{ClassName}. Returns
     * the absolute file path.
     */
    protected function writeAppFile(string $relativePath, string $contents): string
    {
        $relativePath = ltrim($relativePath, '/');
        $path = BASE_PATH . '/app/' . $relativePath;
        $fixtureRoot = explode('/', $relativePath, 2)[0];

        $this->fixtureRoots[BASE_PATH . '/app/' . $fixtureRoot] = true;
        $this->filesystem->ensureDirectoryExists(dirname($path));

        $this->filesystem->put($path, $contents);

        return $path;
    }

    /**
     * Run the facade-documenter subprocess against the given FQCNs with any
     * additional flags. Returns the finished Process so tests can assert on
     * exit code, stdout, and stderr.
     *
     * @param array<int, string> $arguments positional args (facade FQCNs) and flags like --lint, --verbose
     */
    protected function runDocumenter(array $arguments): Process
    {
        $wrapper = realpath(__DIR__ . '/bin/run-with-testbench-autoload.php');

        $process = new Process(
            command: [PHP_BINARY, '-f', $wrapper, '--', ...$arguments],
            env: array_merge($_ENV, ['TESTBENCH_BASE_PATH' => BASE_PATH]),
            timeout: 30,
        );

        $process->run();

        return $process;
    }

    /**
     * Read the contents of a facade file that lives under BASE_PATH/app.
     */
    protected function appFileContents(string $fqcn): string
    {
        $relative = str_replace('\\', '/', substr($fqcn, strlen('App\\')));

        return $this->filesystem->get(BASE_PATH . '/app/' . $relative . '.php');
    }
}
