<?php

declare(strict_types=1);

namespace Hypervel\Tests\Server;

use Hypervel\Contracts\Container\Container;
use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Core\Events\BeforeMainServerStart;
use Hypervel\Core\Events\BeforeServerStart;
use Hypervel\Server\Event;
use Hypervel\Server\Exceptions\ServerException;
use Hypervel\Server\Server;
use Hypervel\Server\ServerConfig;
use Hypervel\Server\ServerInterface;
use Hypervel\Server\ServerManager;
use Hypervel\Tests\TestCase;
use Mockery as m;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Psr\Log\LoggerInterface;

class ServerNativeTest extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    // A real Swoole\Server owns process-global native lifecycle state that a reusable
    // PHPUnit worker must not inherit. On the 6.2.2 floor the symptom is concrete: the
    // discarded server leaves SwooleG.server set, and later coroutine timers never fire.
    #[RunInSeparateProcess]
    public function testSecondarySettingsFailureStopsConfigurationBeforePublication(): void
    {
        if (! defined('SWOOLE_SSL')) {
            $this->markTestSkipped('Swoole was not compiled with SSL support.');
        }

        $events = [];
        $dispatcher = m::mock(Dispatcher::class);
        $dispatcher->expects('dispatch')->twice()->andReturnUsing(
            static function (object $event) use (&$events): null {
                $events[] = $event;

                return null;
            },
        );
        $container = m::mock(Container::class);
        $container->shouldNotReceive('has');
        $server = new ServerNativeTestServer(
            $container,
            m::mock(LoggerInterface::class),
            $dispatcher,
        );
        $warnings = [];

        set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return str_contains($message, 'invalid SNI_cert setting');
        });

        try {
            $server->init(new ServerConfig([
                'servers' => [
                    [
                        'name' => 'http',
                        'type' => ServerInterface::SERVER_HTTP,
                        'host' => '127.0.0.1',
                        'port' => 0,
                    ],
                    [
                        'name' => 'tls',
                        'type' => ServerInterface::SERVER_BASE,
                        'host' => '127.0.0.1',
                        'port' => 0,
                        'sock_type' => SWOOLE_SOCK_TCP | SWOOLE_SSL,
                        'settings' => [
                            'ssl_sni_certs' => ['example.test' => 'not-an-array'],
                        ],
                        'callbacks' => [
                            Event::ON_RECEIVE => static function (): void {
                            },
                            Event::ON_BEFORE_START => [ServerNativeBeforeStartCallback::class, 'handle'],
                        ],
                    ],
                ],
            ]));

            $exception = null;
        } catch (ServerException $exception) {
        } finally {
            restore_error_handler();
        }

        $this->assertInstanceOf(ServerException::class, $exception);
        $this->assertSame('Failed to configure server [tls].', $exception->getMessage());
        $this->assertTrue((bool) array_filter(
            $warnings,
            static fn (string $warning): bool => str_contains($warning, 'invalid SNI_cert setting'),
        ));
        $this->assertSame(
            [BeforeMainServerStart::class, BeforeServerStart::class],
            array_map(static fn (object $event): string => $event::class, $events),
        );
        $this->assertFalse(ServerManager::has('tls'));
        $this->assertNull($server->getServer()->ports[1]->getCallback(Event::ON_RECEIVE));
    }
}

class ServerNativeTestServer extends Server
{
    protected function defaultCallbacks(): array
    {
        return [];
    }
}

class ServerNativeBeforeStartCallback
{
    public function handle(): void
    {
    }
}
