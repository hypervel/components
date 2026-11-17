<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server\Listeners;

use Hypervel\Config\Repository as ConfigRepository;
use Hypervel\Container\Container;
use Hypervel\Context\CoroutineContext;
use Hypervel\ServerProcess\Events\BeforeProcessHandle;
use Hypervel\Tests\Server\Fixtures\DemoProcess;
use Hypervel\Tests\Server\Fixtures\InitProcessTitleListenerStub;
use Hypervel\Tests\Server\Fixtures\InitProcessTitleListenerStub2;
use Hypervel\Tests\TestCase;

class InitProcessTitleListenerTest extends TestCase
{
    public function testProcessDefaultName(): void
    {
        $listener = new InitProcessTitleListenerStub(new ConfigRepository([
            'app' => ['name' => ''],
        ]));
        $process = $this->process();

        $listener->handle(new BeforeProcessHandle($process, 1));

        if (! $listener->isSupportedOS()) {
            $this->assertNull(CoroutineContext::get('test.server.process.title'));
        } else {
            $this->assertSame('test.demo.1', CoroutineContext::get('test.server.process.title'));
        }
    }

    public function testProcessName(): void
    {
        $name = 'hypervel-skeleton.' . uniqid();
        $listener = new InitProcessTitleListenerStub(new ConfigRepository([
            'app' => ['name' => $name],
        ]));
        $process = $this->process();

        $listener->handle(new BeforeProcessHandle($process, 0));

        if (! $listener->isSupportedOS()) {
            $this->assertNull(CoroutineContext::get('test.server.process.title'));
        } else {
            $this->assertSame($name . '.test.demo.0', CoroutineContext::get('test.server.process.title'));
        }
    }

    public function testUserDefinedDot(): void
    {
        $name = 'hypervel-skeleton.' . uniqid();
        $listener = new InitProcessTitleListenerStub2(new ConfigRepository([
            'app' => ['name' => $name],
        ]));
        $process = $this->process();

        $listener->handle(new BeforeProcessHandle($process, 0));

        if (! $listener->isSupportedOS()) {
            $this->assertNull(CoroutineContext::get('test.server.process.title'));
        } else {
            $this->assertSame($name . '#test.demo#0', CoroutineContext::get('test.server.process.title'));
        }
    }

    public function testProcessNameUsesTheReloadedConfigRepository(): void
    {
        $config = new ConfigRepository([
            'app' => ['name' => 'BeforeReload'],
        ]);
        $listener = new InitProcessTitleListenerStub($config);
        $process = $this->process();

        $listener->handle(new BeforeProcessHandle($process, 0));
        $config->set('app.name', 'AfterReload');
        $listener->handle(new BeforeProcessHandle($process, 0));

        if (! $listener->isSupportedOS()) {
            $this->assertNull(CoroutineContext::get('test.server.process.title'));
        } else {
            $this->assertSame('AfterReload.test.demo.0', CoroutineContext::get('test.server.process.title'));
        }
    }

    /**
     * Create the process without an event dispatcher.
     */
    private function process(): DemoProcess
    {
        return new DemoProcess(new Container);
    }
}
