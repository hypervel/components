<?php

declare(strict_types=1);

namespace Hypervel\Tests\Concurrency;

use Hypervel\Concurrency\ProcessDriver;
use Hypervel\Foundation\Application;
use Hypervel\Log\Context\Repository as ContextRepository;
use Hypervel\Process\Factory as ProcessFactory;
use Hypervel\Process\FakeProcessResult;
use Hypervel\Process\PendingProcess;
use Hypervel\Support\Facades\Facade;
use Hypervel\Tests\TestCase;

class ProcessDriverWithoutFacadesTest extends TestCase
{
    /**
     * Clear the facade application before exercising the process driver.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
    }

    public function testRunPassesContextWhenFacadesAreNotRegistered(): void
    {
        $app = new Application(__DIR__);
        $app->make(ContextRepository::class)->add('foo', 'bar');

        $factory = new ProcessFactory;
        $factory->fake(fn (PendingProcess $process): FakeProcessResult => $factory->result($this->signedEnvelope($process, 'result')));

        $this->assertSame(['result'], (new ProcessDriver($factory))->run([fn (): string => 'result']));

        $factory->assertRan(function (PendingProcess $process): bool {
            $context = unserialize(base64_decode($process->environment['__HYPERVEL_CONTEXT']), ['allowed_classes' => false]);

            return unserialize($context['data']['foo']) === 'bar';
        });
    }

    public function testDeferPassesContextWhenFacadesAreNotRegistered(): void
    {
        $app = new Application(__DIR__);
        $app->make(ContextRepository::class)->add('foo', 'bar');

        $factory = new ProcessFactory;
        $factory->fake();

        (new ProcessDriver($factory))->defer([fn (): string => 'result'])();

        $factory->assertRan(function (PendingProcess $process): bool {
            $context = unserialize(base64_decode($process->environment['__HYPERVEL_CONTEXT']), ['allowed_classes' => false]);

            return unserialize($context['data']['foo']) === 'bar';
        });
    }

    /**
     * Build a signed child process result.
     */
    protected function signedEnvelope(PendingProcess $process, mixed $result): string
    {
        $encoded = base64_encode(json_encode(['successful' => true, 'result' => base64_encode(serialize($result))], JSON_THROW_ON_ERROR));
        $signature = hash_hmac('sha256', $encoded, $process->environment['HYPERVEL_CONCURRENCY_HMAC_KEY']);

        return "\n@@HYPERVEL-CONCURRENCY-V1@@{$encoded}@@{$signature}@@\n";
    }
}
