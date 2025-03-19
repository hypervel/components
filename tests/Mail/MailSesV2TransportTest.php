<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\ViewEngine\Contract\FactoryInterface as ViewFactory;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Transport\SesV2Transport;
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
class MailSesV2TransportTest extends TestCase
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

        /** @var \Hypervel\Mail\Transport\SesV2Transport $transport */
        $transport = $manager->createSymfonyTransport(['transport' => 'ses-v2']);

        $ses = $transport->ses();

        $this->assertSame('us-east-1', $ses->getRegion());

        $this->assertSame('ses-v2', (string) $transport);
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

        $client = m::mock(SesV2Client::class);
        $sesResult = m::mock();
        $sesResult->shouldReceive('get')
            ->with('MessageId')
            ->once()
            ->andReturn('ses-message-id');
        $client->shouldReceive('sendEmail')->once()
            ->with(m::on(function ($arg) {
                return $arg['Source'] === 'myself@example.com'
                    && $arg['Destination']['ToAddresses'] === ['me@example.com', 'you@example.com']
                    && $arg['EmailTags'] === [['Name' => 'FooTag', 'Value' => 'TagValue']]
                    && strpos($arg['Content']['Raw']['Data'], 'Reply-To: Taylor Otwell <taylor@example.com>') !== false;
            }))
            ->andReturn($sesResult);

        (new SesV2Transport($client))->send($message);
    }

    public function testSendError()
    {
        $message = new Email();
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');

        $client = m::mock(SesV2Client::class);
        $client->shouldReceive('sendEmail')->once()
            ->andThrow(new AwsException('Email address is not verified.', new Command('sendRawEmail')));

        $this->expectException(TransportException::class);

        (new SesV2Transport($client))->send($message);
    }

    public function testSesV2LocalConfiguration()
    {
        $container = $this->mockContainer();
        $container->get(ConfigInterface::class)->set('mail', [
            'mailers' => [
                'ses' => [
                    'transport' => 'ses-v2',
                    'region' => 'eu-west-1',
                    'options' => [
                        'ConfigurationSetName' => 'Hypervel',
                        'EmailTags' => [
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

        /** @var \Hypervel\Mail\Transport\SesV2Transport $transport */
        $transport = $mailer->getSymfonyTransport();

        $this->assertSame('eu-west-1', $transport->ses()->getRegion());

        $this->assertSame([
            'ConfigurationSetName' => 'Hypervel',
            'EmailTags' => [
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
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }
}
