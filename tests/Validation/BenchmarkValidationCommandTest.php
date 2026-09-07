<?php

declare(strict_types=1);

namespace Hypervel\Tests\Validation;

use Hypervel\Console\Command;
use Hypervel\Testbench\TestCase;
use Hypervel\Validation\Console\BenchmarkValidationCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class BenchmarkValidationCommandTest extends TestCase
{
    public function testKnownScenarioRunsSuccessfully(): void
    {
        [$status, $output] = $this->runCommand([
            '--scenarios' => 'flat',
            '--iterations' => '1',
        ]);

        $this->assertSame(Command::SUCCESS, $status);
        $this->assertStringContainsString('Benchmarking flat', $output);
        $this->assertStringContainsString('median of 1 measured iteration', $output);
    }

    public function testUnknownScenarioFailsWithoutRunningAnotherWorkload(): void
    {
        [$status, $output] = $this->runCommand([
            '--scenarios' => 'missing',
            '--iterations' => '1',
        ]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString('Invalid scenario: missing.', $output);
        $this->assertStringNotContainsString('Benchmarking', $output);
    }

    #[DataProvider('invalidIterationsProvider')]
    public function testInvalidIterationsFail(string $iterations): void
    {
        [$status, $output] = $this->runCommand([
            '--scenarios' => 'flat',
            '--iterations' => $iterations,
        ]);

        $this->assertSame(Command::FAILURE, $status);
        $this->assertStringContainsString("Invalid iterations: {$iterations}.", $output);
        $this->assertStringNotContainsString('Benchmarking', $output);
    }

    public static function invalidIterationsProvider(): array
    {
        return [
            'zero' => ['0'],
            'non-integer' => ['invalid'],
        ];
    }

    /**
     * Run the benchmark command with the given input.
     *
     * @return array{0: int, 1: string}
     */
    private function runCommand(array $input): array
    {
        $command = new BenchmarkValidationCommand;
        $command->setHypervel($this->app);
        $output = new BufferedOutput;

        return [$command->run(new ArrayInput($input), $output), $output->fetch()];
    }
}
