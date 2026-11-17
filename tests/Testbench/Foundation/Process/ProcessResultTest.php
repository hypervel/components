<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testbench\Foundation\Process;

use Exception;
use Hypervel\Testbench\Foundation\Process\ProcessResult;
use Hypervel\Tests\Concurrency\Fixtures\ConcurrentProcessExceptionFixtures;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Process\Process;

class ProcessResultTest extends TestCase
{
    use VerifiesDoubles;

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
        $caught = null;

        try {
            $result->output();
        } catch (Exception $exception) {
            $caught = $exception;
        }

        $this->assertNotNull($caught, 'Expected the transported exception to be thrown.');
        $this->assertSame(ConcurrentProcessExceptionFixtures::PUBLIC_FALSEY_EXCEPTION, $caught::class);
        $this->assertSame(0, $caught->status);
        $this->assertFalse($caught->retry);
        $this->assertSame('', $caught->reason);
        $this->assertNull($caught->detail);
    }

    public function testItReturnsRawNonClosureOutputWithoutInterpretingGzipMarkers(): void
    {
        $output = "raw\x1f\x8boutput";
        $process = Double::for(Process::class)->strict();
        // Symfony's destructor stops the process even when no child was started.
        $process->allows('stop')->with(0.0);
        $process->expects('getOutput')->returns($output);
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
        $key = 'test-envelope-key';
        $encoded = base64_encode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encoded, $key);
        $process = Double::for(Process::class)->strict();
        // Symfony's destructor stops the process even when no child was started.
        $process->allows('stop')->with(0.0);
        $process->expects('getOutput')->returns("\n@@HYPERVEL-CONCURRENCY-V1@@{$encoded}@@{$signature}@@\n");
        $process->expects('getEnv')->returns(['HYPERVEL_CONCURRENCY_HMAC_KEY' => $key]);

        return new ProcessResult($process, static fn (): null => null);
    }
}
