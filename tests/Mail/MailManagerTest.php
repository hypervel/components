<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hyperf\Config\Config;
use Hypervel\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\ViewEngine\Contract\FactoryInterface as ViewFactory;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\TransportPoolProxy;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolManager;
use InvalidArgumentException;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * @internal
 * @coversNothing
 */
class MailManagerTest extends TestCase
{
    /**
     * @dataProvider emptyTransportConfigDataProvider
     * @param mixed $transport
     */
    public function testEmptyTransportConfig($transport)
    {
        $container = $this->getContainer();
        $container->get(ConfigInterface::class)
            ->set('mail.mailers.custom_smtp', [
                'transport' => $transport,
                'host' => null,
                'port' => null,
                'encryption' => null,
                'username' => null,
                'password' => null,
                'timeout' => null,
            ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage("Unsupported mail transport [{$transport}]");

        (new MailManager($container))
            ->mailer('custom_smtp');
    }

    public static function emptyTransportConfigDataProvider()
    {
        return [
            [null],
            [''],
            [' '],
        ];
    }

    public function testMailUrlConfig()
    {
        $container = $this->getContainer();
        $container->get(ConfigInterface::class)
            ->set('mail.mailers.smtp_url', [
                'url' => 'smtp://usr:pwd@127.0.0.2:5876',
            ]);

        $transport = (new MailManager($container))
            ->removePoolable('smtp')
            ->mailer('smtp_url')
            ->getSymfonyTransport(); // @phpstan-ignore-line

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('usr', $transport->getUsername());
        $this->assertSame('pwd', $transport->getPassword());
        $this->assertSame('127.0.0.2', $transport->getStream()->getHost());
        $this->assertSame(5876, $transport->getStream()->getPort());
    }

    public function testPoolableMailUrlConfig()
    {
        $container = $this->getContainer();
        $container->get(ConfigInterface::class)
            ->set('mail.mailers.smtp_url', [
                'url' => 'smtp://usr:pwd@127.0.0.2:5876',
            ]);

        $transport = (new MailManager($container))
            ->mailer('smtp_url')
            ->getSymfonyTransport(); // @phpstan-ignore-line

        $this->assertInstanceOf(TransportPoolProxy::class, $transport);
    }

    protected function getContainer(): Container
    {
        $container = new Container(
            new DefinitionSource([
                ConfigInterface::class => fn () => new Config([]),
                ViewFactory::class => fn () => Mockery::mock(ViewFactory::class),
                EventDispatcherInterface::class => fn () => Mockery::mock(EventDispatcherInterface::class),
                PoolFactory::class => PoolManager::class,
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }
}
