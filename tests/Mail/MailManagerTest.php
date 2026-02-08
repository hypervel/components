<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hyperf\ViewEngine\Contract\FactoryInterface as ViewFactory;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\TransportPoolProxy;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

/**
 * @internal
 * @coversNothing
 */
class MailManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->set(ViewFactory::class, m::mock(ViewFactory::class));
    }

    /**
     * @dataProvider emptyTransportConfigDataProvider
     * @param mixed $transport
     */
    public function testEmptyTransportConfig($transport)
    {
        $this->app->get('config')
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

        (new MailManager($this->app))
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
        $this->app->get('config')
            ->set('mail.mailers.smtp_url', [
                'url' => 'smtp://usr:pwd@127.0.0.2:5876',
            ]);

        $transport = (new MailManager($this->app))
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
        $this->app->get('config')
            ->set('mail.mailers.smtp_url', [
                'url' => 'smtp://usr:pwd@127.0.0.2:5876',
            ]);

        $transport = (new MailManager($this->app))
            ->mailer('smtp_url')
            ->getSymfonyTransport(); // @phpstan-ignore-line

        $this->assertInstanceOf(TransportPoolProxy::class, $transport);
    }
}
