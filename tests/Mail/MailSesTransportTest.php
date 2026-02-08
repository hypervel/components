<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Ses\SesClient;
use Hyperf\Contract\ConfigInterface;
use Hyperf\ViewEngine\Contract\FactoryInterface as ViewFactory;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Transport\SesTransport;
use Hypervel\Testbench\TestCase;
use Mockery as m;
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
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->set(ViewFactory::class, m::mock(ViewFactory::class));
    }

    public function testGetTransport()
    {
        $this->app->get(ConfigInterface::class)->set('services.ses', [
            'key' => 'foo',
            'secret' => 'bar',
            'region' => 'us-east-1',
        ]);

        $manager = new MailManager($this->app);

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
        $this->app->get(ConfigInterface::class)->set('mail', [
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
        $this->app->get(ConfigInterface::class)->set('services', [
            'ses' => [
                'region' => 'us-east-1',
            ],
        ]);

        $manager = new MailManager($this->app);

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
}
