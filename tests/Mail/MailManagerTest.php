<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\TransportPoolProxy;
use Hypervel\Support\ClassInvoker;
use Hypervel\Testbench\TestCase;
use InvalidArgumentException;
use Mockery as m;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class MailManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('view', m::mock(ViewFactory::class));
    }

    #[DataProvider('emptyTransportConfigDataProvider')]
    public function testEmptyTransportConfig($transport)
    {
        $this->app->make('config')
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

    #[TestWith([null, 5876])]
    #[TestWith([null, 465])]
    #[TestWith(['smtp', 25])]
    #[TestWith(['smtp', 2525])]
    #[TestWith(['smtps', 465])]
    #[TestWith(['smtp', 465])]
    public function testMailUrlConfig($scheme, $port)
    {
        $this->app->make('config')
            ->set('mail.mailers.smtp_url', [
                'scheme' => $scheme,
                'url' => "smtp://usr:pwd@127.0.0.2:{$port}",
            ]);

        $transport = (new MailManager($this->app))
            ->removePoolable('smtp')
            ->mailer('smtp_url')
            ->getSymfonyTransport(); // @phpstan-ignore-line

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('usr', $transport->getUsername());
        $this->assertSame('pwd', $transport->getPassword());
        $this->assertSame('127.0.0.2', $transport->getStream()->getHost());
        $this->assertSame($port, $transport->getStream()->getPort());
        $this->assertSame($port === 465, $transport->getStream()->isTLS());
        $this->assertTrue($transport->isAutoTls());
    }

    #[TestWith([null, 5876])]
    #[TestWith([null, 465])]
    #[TestWith(['smtp', 25])]
    #[TestWith(['smtp', 2525])]
    #[TestWith(['smtps', 465])]
    #[TestWith(['smtp', 465])]
    public function testMailUrlConfigWithAutoTls($scheme, $port)
    {
        $this->app->make('config')
            ->set('mail.mailers.smtp_url', [
                'scheme' => $scheme,
                'url' => "smtp://usr:pwd@127.0.0.2:{$port}?auto_tls=true",
            ]);

        $transport = (new MailManager($this->app))
            ->removePoolable('smtp')
            ->mailer('smtp_url')
            ->getSymfonyTransport(); // @phpstan-ignore-line

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('usr', $transport->getUsername());
        $this->assertSame('pwd', $transport->getPassword());
        $this->assertSame('127.0.0.2', $transport->getStream()->getHost());
        $this->assertSame($port, $transport->getStream()->getPort());
        $this->assertSame($port === 465, $transport->getStream()->isTLS());
        $this->assertTrue($transport->isAutoTls());
    }

    #[TestWith([null, 5876])]
    #[TestWith([null, 465])]
    #[TestWith(['smtp', 25])]
    #[TestWith(['smtp', 2525])]
    #[TestWith(['smtps', 465])]
    #[TestWith(['smtp', 465])]
    public function testMailUrlConfigWithAutoTlsDisabled($scheme, $port)
    {
        $this->app->make('config')
            ->set('mail.mailers.smtp_url', [
                'scheme' => $scheme,
                'url' => "smtp://usr:pwd@127.0.0.2:{$port}?auto_tls=false",
            ]);

        $transport = (new MailManager($this->app))
            ->removePoolable('smtp')
            ->mailer('smtp_url')
            ->getSymfonyTransport(); // @phpstan-ignore-line

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('usr', $transport->getUsername());
        $this->assertSame('pwd', $transport->getPassword());
        $this->assertSame('127.0.0.2', $transport->getStream()->getHost());
        $this->assertSame($port, $transport->getStream()->getPort());
        $this->assertFalse($transport->isAutoTls());
        $this->assertSame($port === 465 && $scheme !== 'smtp', $transport->getStream()->isTLS());
    }

    public function testBuild()
    {
        $config = [
            'transport' => 'smtp',
            'host' => '127.0.0.2',
            'port' => 5876,
            'encryption' => 'tls',
            'username' => 'usr',
            'password' => 'pwd',
            'timeout' => 5,
        ];

        $transport = (new MailManager($this->app))
            ->build($config)
            ->getSymfonyTransport();

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame('usr', $transport->getUsername());
        $this->assertSame('pwd', $transport->getPassword());
        $this->assertSame('127.0.0.2', $transport->getStream()->getHost());
        $this->assertSame(5876, $transport->getStream()->getPort());
    }

    public function testSmtpTransportUsesSecureSchemeWhenPortIsConfiguredAsString(): void
    {
        $transport = (new MailManager($this->app))
            ->createSymfonyTransport([
                'transport' => 'smtp',
                'host' => '127.0.0.2',
                'port' => '465',
                'username' => null,
                'password' => null,
            ]);

        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertSame(465, $transport->getStream()->getPort());
        $this->assertTrue($transport->getStream()->isTLS());
    }

    public function testPoolableMailUrlConfig()
    {
        $this->app->make('config')
            ->set('mail.mailers.smtp_url', [
                'url' => 'smtp://usr:pwd@127.0.0.2:5876',
            ]);

        $transport = (new MailManager($this->app))
            ->mailer('smtp_url')
            ->getSymfonyTransport(); // @phpstan-ignore-line

        $this->assertInstanceOf(TransportPoolProxy::class, $transport);
    }

    public function testPostmarkTransportCanBeCreatedDirectly(): void
    {
        $transport = (new MailManager($this->app))
            ->createSymfonyTransport([
                'transport' => 'postmark',
                'key' => 'postmark-key',
            ]);

        $this->assertInstanceOf(PostmarkApiTransport::class, $transport);
        $this->assertSame('postmark-key', (new ClassInvoker($transport))->key);
    }

    public function testPostmarkTransportAcceptsTokenConfiguration(): void
    {
        $transport = (new MailManager($this->app))
            ->createSymfonyTransport([
                'transport' => 'postmark',
                'token' => 'postmark-token',
                'key' => 'postmark-key',
            ]);

        $this->assertInstanceOf(PostmarkApiTransport::class, $transport);
        $this->assertSame('postmark-token', (new ClassInvoker($transport))->key);
    }

    #[DataProvider('poolableTransportDataProvider')]
    public function testConfiguredPoolableTransportsResolveToPoolProxy(string $transport): void
    {
        $this->app->make('config')
            ->set("mail.mailers.{$transport}", [
                'transport' => $transport,
                'host' => '127.0.0.1',
                'port' => 2525,
                'username' => null,
                'password' => null,
                'path' => '/usr/sbin/sendmail -bs -i',
                'mailers' => ['smtp', 'log'],
            ]);

        $transport = (new MailManager($this->app))
            ->mailer($transport)
            ->getSymfonyTransport(); // @phpstan-ignore-line

        $this->assertInstanceOf(TransportPoolProxy::class, $transport);
    }

    public static function poolableTransportDataProvider(): array
    {
        return [
            ['smtp'],
            ['sendmail'],
            ['mailgun'],
            ['ses-v2'],
            ['postmark'],
            ['resend'],
            ['cloudflare'],
            ['failover'],
            ['roundrobin'],
        ];
    }
}
