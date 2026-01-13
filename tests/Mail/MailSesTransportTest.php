<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Transport\SesTransport;
use Hypervel\ObjectPool\Contracts\Factory as PoolFactory;
use Hypervel\ObjectPool\PoolManager;
use Hypervel\View\Contracts\Factory as ViewFactory;
use Mockery as m;
use PHPUnit\Framework\TestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @internal
 * @coversNothing
 */
class MailSesTransportTest extends TestCase
{
    protected function tearDown(): void
    {
        m::close();

        parent::tearDown();
    }

    public function testGetTransport()
    {
        $container = $this->mockContainer();
        $container->get(ConfigInterface::class)->set('services.ses', [
            'key' => 'foo',
            'secret' => 'bar',
            'region' => 'us-east-1',
        ]);

        $manager = new MailManager($container);

        /** @var \Hypervel\Mail\Transport\SesTransport $transport */
        $transport = $manager->createSymfonyTransport(['transport' => 'ses']);

        $ses = $transport->ses();

        $this->assertSame('us-east-1', $ses->getRegion());

        $this->assertSame('ses', (string) $transport);
    }

    public function testSend()
    {
        $message = new Email();
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');
        $message->bcc('you@example.com');
        $message->replyTo(new Address('taylor@example.com', 'Taylor Otwell'));
        $message->getHeaders()->add(new MetadataHeader('FooTag', 'TagValue'));

        $client = m::mock(SesClient::class);
        $sesResult = m::mock();
        $sesResult->shouldReceive('get')
            ->with('MessageId')
            ->once()
            ->andReturn('ses-message-id');
        $client->shouldReceive('sendRawEmail')->once()
            ->with(m::on(function ($arg) {
                return $arg['Source'] === 'myself@example.com'
                    && $arg['Destinations'] === ['me@example.com', 'you@example.com']
                    && $arg['Tags'] === [['Name' => 'FooTag', 'Value' => 'TagValue']]
                    && strpos($arg['RawMessage']['Data'], 'Reply-To: Taylor Otwell <taylor@example.com>') !== false;
            }))
            ->andReturn($sesResult);

        (new SesTransport($client))->send($message);
    }

    public function testSendError()
    {
        $message = new Email();
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');

        $client = m::mock(SesClient::class);
        $client->shouldReceive('sendRawEmail')->once()
            ->andThrow(new AwsException('Email address is not verified.', new Command('sendRawEmail')));

        $this->expectException(TransportException::class);

        (new SesTransport($client))->send($message);
    }

    public function testSesLocalConfiguration()
    {
        $container = $this->mockContainer();
        $container->get(ConfigInterface::class)->set('mail', [
            'mailers' => [
                'ses' => [
                    'transport' => 'ses',
                    'region' => 'eu-west-1',
                    'options' => [
                        'ConfigurationSetName' => 'Hypervel',
                        'Tags' => [
                            ['Name' => 'Hypervel', 'Value' => 'Framework'],
                        ],
                    ],
                ],
            ],
        ]);
        $container->get(ConfigInterface::class)->set('services', [
            'ses' => [
                'region' => 'us-east-1',
            ],
        ]);

        $manager = new MailManager($container);

        /** @var \Hypervel\Mail\Mailer $mailer */
        $mailer = $manager->mailer('ses');

        /** @var \Hypervel\Mail\Transport\SesTransport $transport */
        $transport = $mailer->getSymfonyTransport();

        $this->assertSame('eu-west-1', $transport->ses()->getRegion());

        $this->assertSame([
            'ConfigurationSetName' => 'Hypervel',
            'Tags' => [
                ['Name' => 'Hypervel', 'Value' => 'Framework'],
            ],
        ], $transport->getOptions());
    }

    protected function mockContainer(): Container
    {
        $container = new Container(
            new DefinitionSource([
                ConfigInterface::class => fn () => new Config([]),
                ViewFactory::class => fn () => m::mock(ViewFactory::class),
                EventDispatcherInterface::class => fn () => m::mock(EventDispatcherInterface::class),
                PoolFactory::class => PoolManager::class,
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }
}
