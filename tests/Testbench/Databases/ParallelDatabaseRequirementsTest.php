<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Databases;

use Hypervel\Filesystem\Filesystem;
use Hypervel\Foundation\Testing\DatabaseMigrations;
use Hypervel\Testbench\Attributes\RequiresDatabase;
use Hypervel\Testbench\TestCase as TestbenchTestCase;
use Hypervel\Testing\ParallelTesting;
use Hypervel\Tests\TestCase;

class ParallelDatabaseRequirementsTest extends TestCase
{
    public function testDatabaseExistsBeforeVersionRequirementsRun(): void
    {
        $directory = ParallelTesting::tempDir('ParallelDatabaseRequirementsTest');
        $files = new Filesystem;
        $files->deleteDirectory($directory);
        $files->ensureDirectoryExists($directory);
        $database = $directory . '/database.sqlite';
        touch($database);

        $environment = [
            'TEST_TOKEN' => (string) (env('TEST_TOKEN') ?? 'requirements'),
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
        ];
        $previous = [];
        $previousWithoutDatabasesExists = array_key_exists('HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES', $_SERVER);
        $previousWithoutDatabases = $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] ?? null;
        unset($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES']);

        foreach ($environment as $name => $value) {
            $previous[$name] = [
                getenv($name),
                array_key_exists($name, $_SERVER),
                $_SERVER[$name] ?? null,
                array_key_exists($name, $_ENV),
                $_ENV[$name] ?? null,
            ];
            putenv("{$name}={$value}");
            $_SERVER[$name] = $_ENV[$name] = $value;
        }

        try {
            $fixture = new ParallelDatabaseRequirementsFixture('start');
            ParallelDatabaseRequirementsFixture::setUpBeforeClass();

            try {
                try {
                    $fixture->start();

                    $this->assertFileExists($database . '_test_' . $environment['TEST_TOKEN']);
                } finally {
                    $fixture->finish();
                }
            } finally {
                ParallelDatabaseRequirementsFixture::tearDownAfterClass();
            }
        } finally {
            if ($previousWithoutDatabasesExists) {
                $_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = $previousWithoutDatabases;
            } else {
                unset($_SERVER['HYPERVEL_PARALLEL_TESTING_WITHOUT_DATABASES']);
            }

            foreach ($previous as $name => [$processValue, $serverExists, $serverValue, $environmentExists, $environmentValue]) {
                $processValue === false ? putenv($name) : putenv("{$name}={$processValue}");

                if ($serverExists) {
                    $_SERVER[$name] = $serverValue;
                } else {
                    unset($_SERVER[$name]);
                }

                if ($environmentExists) {
                    $_ENV[$name] = $environmentValue;
                } else {
                    unset($_ENV[$name]);
                }
            }

            $files->deleteDirectory($directory);
        }
    }
}

#[RequiresDatabase('sqlite', '>=3.0.0')]
class ParallelDatabaseRequirementsFixture extends TestbenchTestCase
{
    use DatabaseMigrations;

    /**
     * Start the database test lifecycle.
     */
    public function start(): void
    {
        $this->setUp();
    }

    /**
     * Finish the database test lifecycle.
     */
    public function finish(): void
    {
        $this->tearDown();
    }
}
