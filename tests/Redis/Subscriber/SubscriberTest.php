<?php

declare(strict_types=1);

namespace Hypervel\Tests\Redis\Subscriber;

use Hypervel\Engine\Channel;
use Hypervel\Redis\Subscriber\CommandBuilder;
use Hypervel\Redis\Subscriber\CommandInvoker;
use Hypervel\Redis\Subscriber\Exceptions\SubscribeException;
use Hypervel\Redis\Subscriber\Subscriber;
use Hypervel\Tests\Redis\Fixtures\RespServer;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;

class SubscriberTest extends TestCase
{
    use VerifiesDoubles;

    public function testSubscribeDelegatesToCommandInvoker(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['subscribe', 'foo', 'bar'], 2)
            ->returns([
                ['subscribe', 'foo', 1],
                ['subscribe', 'bar', 2],
            ]);

        $subscriber = $this->createSubscriber($invoker);
        $subscriber->subscribe('foo', 'bar');
    }

    public function testSubscribePrependsPrefix(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['subscribe', 'app:foo', 'app:bar'], 2)
            ->returns([
                ['subscribe', 'app:foo', 1],
                ['subscribe', 'app:bar', 2],
            ]);

        $subscriber = $this->createSubscriber($invoker, prefix: 'app:');
        $subscriber->subscribe('foo', 'bar');
    }

    public function testSubscribeRejectsAnEmptyChannelListBeforeSending(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')->never();

        $subscriber = $this->createSubscriber($invoker);

        $this->expectException(SubscribeException::class);
        $this->expectExceptionMessageIs('At least one Redis channel is required.');

        $subscriber->subscribe();
    }

    public function testUnsubscribeDelegatesToCommandInvoker(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['unsubscribe', 'foo'], 1)
            ->returns([['unsubscribe', 'foo', 0]]);

        $subscriber = $this->createSubscriber($invoker);
        $subscriber->unsubscribe('foo');
    }

    public function testUnsubscribePrependsPrefix(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['unsubscribe', 'app:foo'], 1)
            ->returns([['unsubscribe', 'app:foo', 0]]);

        $subscriber = $this->createSubscriber($invoker, prefix: 'app:');
        $subscriber->unsubscribe('foo');
    }

    public function testUnsubscribeAllWaitsForEveryTrackedChannelAndKeepsPatternsSeparate(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['subscribe', 'app:one', 'app:two'], 2)
            ->returns([
                ['subscribe', 'app:one', 1],
                ['subscribe', 'app:two', 2],
            ]);
        $invoker->expects('invoke')
            ->with(['psubscribe', 'app:events.*'], 1)
            ->returns([['psubscribe', 'app:events.*', 3]]);
        $invoker->expects('invoke')
            ->with(['unsubscribe'], 2)
            ->returns([
                ['unsubscribe', 'app:one', 2],
                ['unsubscribe', 'app:two', 1],
            ]);
        $invoker->expects('invoke')
            ->with(['unsubscribe'], 1)
            ->returns([['unsubscribe', null, 1]]);
        $invoker->expects('invoke')
            ->with(['punsubscribe'], 1)
            ->returns([['punsubscribe', 'app:events.*', 0]]);

        $subscriber = $this->createSubscriber($invoker, prefix: 'app:');
        $subscriber->subscribe('one', 'two');
        $subscriber->psubscribe('events.*');
        $subscriber->unsubscribe();
        $subscriber->unsubscribe();
        $subscriber->punsubscribe();
    }

    public function testAllUnsubscribeWithNoTrackedChannelsWaitsForOneAcknowledgement(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['unsubscribe'], 1)
            ->returns([['unsubscribe', null, 0]]);

        $this->createSubscriber($invoker)->unsubscribe();
    }

    public function testPsubscribeDelegatesToCommandInvoker(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['psubscribe', 'foo.*', 'bar.*'], 2)
            ->returns([
                ['psubscribe', 'foo.*', 1],
                ['psubscribe', 'bar.*', 2],
            ]);

        $subscriber = $this->createSubscriber($invoker);
        $subscriber->psubscribe('foo.*', 'bar.*');
    }

    public function testPsubscribePrependsPrefix(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['psubscribe', 'app:events.*'], 1)
            ->returns([['psubscribe', 'app:events.*', 1]]);

        $subscriber = $this->createSubscriber($invoker, prefix: 'app:');
        $subscriber->psubscribe('events.*');
    }

    public function testPsubscribeRejectsAnEmptyPatternListBeforeSending(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')->never();

        $subscriber = $this->createSubscriber($invoker);

        $this->expectException(SubscribeException::class);
        $this->expectExceptionMessageIs('At least one Redis channel pattern is required.');

        $subscriber->psubscribe();
    }

    public function testPunsubscribeDelegatesToCommandInvoker(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['punsubscribe', 'foo.*'], 1)
            ->returns([['punsubscribe', 'foo.*', 0]]);

        $subscriber = $this->createSubscriber($invoker);
        $subscriber->punsubscribe('foo.*');
    }

    public function testPunsubscribePrependsPrefix(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['punsubscribe', 'app:foo.*'], 1)
            ->returns([['punsubscribe', 'app:foo.*', 0]]);

        $subscriber = $this->createSubscriber($invoker, prefix: 'app:');
        $subscriber->punsubscribe('foo.*');
    }

    public function testAllPunsubscribeWithNoTrackedPatternsWaitsForOneAcknowledgement(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('invoke')
            ->with(['punsubscribe'], 1)
            ->returns([['punsubscribe', null, 0]]);

        $this->createSubscriber($invoker)->punsubscribe();
    }

    public function testChannelDelegatesToCommandInvoker(): void
    {
        $channel = new Channel(1);
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('channel')->returns($channel);

        $subscriber = $this->createSubscriber($invoker);

        $this->assertSame($channel, $subscriber->channel());
    }

    public function testCloseSetsClosedAndInterrupts(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('interrupt')->returns(true);

        $subscriber = $this->createSubscriber($invoker);

        $this->assertFalse($subscriber->closed);

        $subscriber->close();

        $this->assertTrue($subscriber->closed);
    }

    public function testPingDelegatesToCommandInvoker(): void
    {
        $invoker = Double::for(CommandInvoker::class)->strict();
        $invoker->expects('ping')->with(2.5)->returns('pong');

        $subscriber = $this->createSubscriber($invoker);

        $this->assertSame('pong', $subscriber->ping(2.5));
    }

    #[DataProvider('credentialProvider')]
    public function testConnectForwardsCompleteCredentialShapes(
        string|array $password,
        ?string $username,
        array $expectedCredentials,
    ): void {
        $command = CommandBuilder::build(['auth', ...$expectedCredentials]);
        $received = '';
        $server = new RespServer;
        $server->start(function ($client) use ($command, &$received): void {
            $received = RespServer::readExact($client, strlen($command));
            fwrite($client, "+OK\r\n");
            fread($client, 1);
        });
        $subscriber = new Subscriber(
            host: $server->endpoint(),
            password: $password,
            username: $username,
        );

        try {
            $this->assertSame($command, $received);
        } finally {
            $subscriber->close();
            $server->wait();
        }
    }

    public static function credentialProvider(): array
    {
        return [
            'scalar password' => ['secret', null, ['secret']],
            'zero password' => ['0', null, ['0']],
            'credential array' => [['user', 'secret'], null, ['user', 'secret']],
            'zero credential array' => [['0', '0'], null, ['0', '0']],
            'zero username and password' => ['0', '0', ['0', '0']],
        ];
    }

    #[DataProvider('absentCredentialProvider')]
    public function testConnectDoesNotAuthenticateWithoutCredentials(?string $password): void
    {
        $received = null;
        $server = new RespServer;
        $server->start(static function ($client) use (&$received): void {
            $received = fread($client, 1);
        });
        $subscriber = new Subscriber(
            host: $server->endpoint(),
            password: $password,
            username: 'unused',
        );

        $subscriber->close();
        $server->wait();

        $this->assertSame('', $received);
    }

    public static function absentCredentialProvider(): array
    {
        return [
            'null' => [null],
            'empty string' => [''],
        ];
    }

    /**
     * Create a Subscriber with a mock CommandInvoker, bypassing the real connection.
     */
    private function createSubscriber(CommandInvoker $invoker, string $prefix = ''): Subscriber
    {
        $reflection = new ReflectionClass(Subscriber::class);
        $subscriber = $reflection->newInstanceWithoutConstructor();

        $subscriber->host = '127.0.0.1';
        $subscriber->port = 6379;
        $subscriber->password = null;
        $subscriber->timeout = 5.0;
        $subscriber->prefix = $prefix;
        $subscriber->username = null;
        $subscriber->scheme = null;
        $subscriber->context = [];
        $subscriber->closed = false;

        $reflection->getProperty('commandInvoker')->setValue($subscriber, $invoker);

        return $subscriber;
    }
}
