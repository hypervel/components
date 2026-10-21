<?php

declare(strict_types=1);

namespace Hypervel\Tests\Workbench\Console;

use Hypervel\Console\Command;
use Hypervel\Contracts\Foundation\Application as ApplicationContract;
use Hypervel\Support\Arr;
use Hypervel\Support\Facades\Artisan;
use Hypervel\Testbench\TestCase;
use Hypervel\Workbench\WorkbenchServiceProvider;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class PackageCommandWrappersTest extends TestCase
{
    #[Override]
    protected function getPackageProviders(ApplicationContract $app): array
    {
        return [
            WorkbenchServiceProvider::class,
        ];
    }

    /**
     * @param array<string, bool|string> $parameters
     * @param array<string, bool|string> $expected
     */
    #[Test]
    #[DataProvider('wrapperCommands')]
    public function itForwardsOptionsAndTheExitStatus(string $command, array $parameters, string $packageCommand, array $expected): void
    {
        $received = null;

        Artisan::command($packageCommand, function () use (&$received): int {
            /** @var Command $this */
            $received = $this->options();

            return Command::FAILURE;
        });

        $this->artisan($command, $parameters)->assertExitCode(Command::FAILURE);

        $this->assertIsArray($received);
        $this->assertSame($expected, Arr::only($received, array_keys($expected)));
    }

    /**
     * Get the Workbench commands that forward to Testbench package commands.
     *
     * @return iterable<string, array{string, array<string, bool|string>, string, array<string, bool|string>}>
     */
    public static function wrapperCommands(): iterable
    {
        yield 'create sqlite database' => [
            'workbench:create-sqlite-db',
            ['--database' => 'courier.sqlite', '--force' => true, '--pretend' => true],
            'package:create-sqlite-db {--database=} {--force} {--pretend}',
            ['database' => 'courier.sqlite', 'force' => true, 'pretend' => true],
        ];

        yield 'drop sqlite database' => [
            'workbench:drop-sqlite-db',
            ['--database' => 'courier.sqlite', '--all' => true, '--pretend' => true],
            'package:drop-sqlite-db {--database=} {--all} {--pretend}',
            ['database' => 'courier.sqlite', 'all' => true, 'pretend' => true],
        ];

        yield 'purge skeleton' => [
            'workbench:purge-skeleton',
            ['--pretend' => true],
            'package:purge-skeleton {--pretend}',
            ['pretend' => true],
        ];

        yield 'sync skeleton' => [
            'workbench:sync-skeleton',
            [],
            'package:sync-skeleton',
            [],
        ];
    }
}
