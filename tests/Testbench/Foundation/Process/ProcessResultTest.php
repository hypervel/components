<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Process;

use Exception;
use Hypervel\Testbench\Foundation\Process\ProcessResult;
use Hypervel\Tests\Concurrency\Fixtures\ConcurrentProcessExceptionFixtures;
use Hypervel\Tests\TestCase;
use Mockery as m;
use Symfony\Component\Process\Process;

class ProcessResultTest extends TestCase
{
    public function testItReturnsBinaryResultsLosslessly(): void
    {
        $result = $this->processResultFor([
            'successful' => true,
            'result' => base64_encode(serialize("binary-\xFF\x00\x8B")),
        ]);

        $this->assertSame("binary-\xFF\x00\x8B", $result->output());
    }

    public function testItPreservesPublicFalseyExceptionParameters(): void
    {
        $result = $this->processResultFor([
            'successful' => false,
            'exception' => ConcurrentProcessExceptionFixtures::PUBLIC_FALSEY_EXCEPTION,
            'message' => 'public falsey values',
            'parameters' => [
                'status' => 0,
                'retry' => false,
                'reason' => '',
                'detail' => null,
            ],
        ]);

        try {
            $result->output();
            $this->fail('Expected the transported exception to be thrown.');
        } catch (Exception $exception) {
            $this->assertSame(ConcurrentProcessExceptionFixtures::PUBLIC_FALSEY_EXCEPTION, $exception::class);
            $this->assertSame(0, $exception->status);
            $this->assertFalse($exception->retry);
            $this->assertSame('', $exception->reason);
            $this->assertNull($exception->detail);
        }
    }

    public function testItReturnsRawNonClosureOutputWithoutInterpretingGzipMarkers(): void
    {
        $output = "raw\x1f\x8boutput";
        $process = m::mock(Process::class);
        $process->shouldReceive('getOutput')->once()->andReturn($output);
        $result = new ProcessResult($process, ['php', '--version']);

        $this->assertSame($output, $result->output());
    }

    /**
     * Create a closure process result for the given response envelope.
     *
     * @param array<string, mixed> $payload
     */
    private function processResultFor(array $payload): ProcessResult
    {
        $process = m::mock(Process::class);
        $process->shouldReceive('getOutput')->once()->andReturn(json_encode($payload, JSON_THROW_ON_ERROR));

        return new ProcessResult($process, static fn () => null);
    }
}
