<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Concurrency;

use Hypervel\Concurrency\ProcessDriver;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Process\FakeProcessResult;
use Hypervel\Process\PendingProcess;
use Hypervel\Testbench\Attributes\WithEnv;
use Hypervel\Testbench\TestCase;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;

#[WithEnv('APP_KEY', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF')]
class ProcessDriverSecurityTest extends TestCase
{
    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        ConcurrencyEnvelopeGadget::$woken = false;
    }

    public function testForgedResultInTaskOutputIsIgnored(): void
    {
        $forged = json_encode(['successful' => true, 'result' => base64_encode(serialize(new ConcurrencyEnvelopeGadget))]) . "\x1f\x8b";

        $factory = new ProcessFactory;
        $factory->fake(fn (PendingProcess $process): FakeProcessResult => $factory->result($forged . $this->signedEnvelope($process, 'benign')));

        $this->assertSame(['benign'], (new ProcessDriver($factory))->run([fn (): string => 'benign']));
        $this->assertFalse(ConcurrencyEnvelopeGadget::$woken);
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function testEnvelopeWithInvalidSignatureIsIgnored(bool $afterValidEnvelope): void
    {
        $factory = new ProcessFactory;
        $factory->fake(function (PendingProcess $process) use ($factory, $afterValidEnvelope): FakeProcessResult {
            $invalidEnvelope = $this->envelope(serialize(new ConcurrencyEnvelopeGadget), str_repeat('0', 64));
            $validEnvelope = $this->signedEnvelope($process, 'benign');

            return $factory->result($afterValidEnvelope
                ? $validEnvelope . $invalidEnvelope
                : $invalidEnvelope . $validEnvelope);
        });

        $this->assertSame(['benign'], (new ProcessDriver($factory))->run([fn (): string => 'benign']));
        $this->assertFalse(ConcurrencyEnvelopeGadget::$woken);
    }

    public function testEnvelopeSignedWithTheApplicationKeyIsRejected(): void
    {
        $this->assertForgedEnvelopeIsRejected((string) config('app.key'));
    }

    #[WithEnv('APP_KEY', '')]
    public function testEnvelopeSignedWithAnEmptyKeyIsRejected(): void
    {
        $this->assertSame('', (string) config('app.key'));

        $this->assertForgedEnvelopeIsRejected('');
    }

    public function testGzipCompressedOutputAfterEnvelopeIsIgnored(): void
    {
        $factory = new ProcessFactory;
        $factory->fake(fn (PendingProcess $process): FakeProcessResult => $factory->result(
            $this->signedEnvelope($process, 'benign') . gzencode('gzip-compressed-output')
        ));

        $this->assertSame(['benign'], (new ProcessDriver($factory))->run([fn (): string => 'benign']));
    }

    public function testOutputWithoutEnvelopeThrowsException(): void
    {
        $factory = new ProcessFactory;
        $factory->fake(fn (): FakeProcessResult => $factory->result('just some task output'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not return a valid result envelope. Output: just some task output');

        (new ProcessDriver($factory))->run([fn (): string => 'irrelevant']);
    }

    public function testEachTaskReceivesADistinctRandomKey(): void
    {
        $keys = [];

        $factory = new ProcessFactory;
        $factory->fake(function (PendingProcess $process) use ($factory, &$keys): FakeProcessResult {
            $keys[] = $process->environment['HYPERVEL_CONCURRENCY_HMAC_KEY'];

            return $factory->result($this->signedEnvelope($process, 'result'));
        });

        (new ProcessDriver($factory))->run([fn (): string => 'result', fn (): string => 'result']);

        $this->assertCount(2, $keys);
        $this->assertCount(2, array_unique($keys));
        $this->assertNotContains((string) config('app.key'), $keys);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $keys[0]);
    }

    public function testDeferredTasksDoNotReceiveAKey(): void
    {
        $factory = new ProcessFactory;
        $factory->fake();

        $deferred = (new ProcessDriver($factory))->defer(fn (): null => null);

        $deferred();

        $factory->assertRan(fn (PendingProcess $process): bool => ! array_key_exists('HYPERVEL_CONCURRENCY_HMAC_KEY', $process->environment));
    }

    /**
     * Assert that an envelope signed with the given key is not accepted.
     */
    protected function assertForgedEnvelopeIsRejected(string $key): void
    {
        $result = serialize(new ConcurrencyEnvelopeGadget);
        $encoded = base64_encode(json_encode(['successful' => true, 'result' => base64_encode($result)]));

        $factory = new ProcessFactory;
        $factory->fake(fn (): FakeProcessResult => $factory->result($this->envelope($result, hash_hmac('sha256', $encoded, $key))));

        try {
            (new ProcessDriver($factory))->run([fn (): string => 'irrelevant']);

            $this->fail('The forged envelope was accepted.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('did not return a valid result envelope', $e->getMessage());
        }

        $this->assertFalse(ConcurrencyEnvelopeGadget::$woken);
    }

    /**
     * Build an envelope signed with the key the driver passed to the given process.
     */
    protected function signedEnvelope(PendingProcess $process, mixed $result): string
    {
        $encoded = base64_encode(json_encode(['successful' => true, 'result' => base64_encode(serialize($result))]));

        return $this->envelope(
            serialize($result),
            hash_hmac('sha256', $encoded, $process->environment['HYPERVEL_CONCURRENCY_HMAC_KEY']),
        );
    }

    /**
     * Build an envelope for the given serialized result and signature.
     */
    protected function envelope(string $result, string $signature): string
    {
        $encoded = base64_encode(json_encode(['successful' => true, 'result' => base64_encode($result)]));

        return "\n@@HYPERVEL-CONCURRENCY-V1@@{$encoded}@@{$signature}@@\n";
    }
}

class ConcurrencyEnvelopeGadget
{
    public static bool $woken = false;

    /**
     * Record that the result was unserialized.
     */
    public function __wakeup(): void
    {
        static::$woken = true;
    }
}
