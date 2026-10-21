<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Mail\Mailer;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Transport\SesV2Transport;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class MailSesV2TransportTest extends TestCase
{
    // REMOVED: Laravel's SES v1 transport tests. Hypervel supports SES v2 only.

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('view', m::mock(ViewFactory::class));
    }

    public function testGetTransport(): void
    {
        $this->app->make('config')->set('services.ses', [
            'key' => 'foo',
            'secret' => 'bar',
            'region' => 'us-east-1',
        ]);

        $manager = new MailManager($this->app);

        /** @var SesV2Transport $transport */
        $transport = $manager->createSymfonyTransport(['transport' => 'ses-v2']);

        $ses = $transport->ses();

        $this->assertSame('us-east-1', $ses->getRegion());

        $this->assertSame('ses-v2', (string) $transport);
    }

    public function testSend(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');
        $message->bcc('you@example.com');
        $message->replyTo(new Address('taylor@example.com', 'Taylor Otwell'));
        $message->getHeaders()->addIdHeader('Message-ID', 'mime-message-id@example.com');
        $message->getHeaders()->add(new MetadataHeader('FooTag', 'TagValue'));
        $message->getHeaders()->addTextHeader('X-SES-LIST-MANAGEMENT-OPTIONS', 'contactListName=TestList;topicName=TestTopic');

        $client = m::mock(SesV2Client::class);
        $sesResult = m::mock();
        $sesResult->expects('get')
            ->with('MessageId')
            ->andReturn('ses-message-id');
        $client->expects('sendEmail')
            ->with(m::on(function (array $arg): bool {
                return $arg['Source'] === 'myself@example.com'
                    && $arg['Destination']['ToAddresses'] === ['me@example.com', 'you@example.com']
                    && $arg['ListManagementOptions'] === ['ContactListName' => 'TestList', 'TopicName' => 'TestTopic']
                    && $arg['EmailTags'] === [['Name' => 'FooTag', 'Value' => 'TagValue']]
                    && str_contains($arg['Content']['Raw']['Data'], 'Reply-To: Taylor Otwell <taylor@example.com>');
            }))
            ->andReturn($sesResult);

        $sentMessage = (new SesV2Transport($client))->send($message);

        $this->assertSame('mime-message-id@example.com', $sentMessage->getMessageId());
        $headers = $sentMessage->getOriginalMessage()->getHeaders();
        $this->assertSame('ses-message-id', $headers->get('X-Message-ID')->getBodyAsString());
        $this->assertSame('ses-message-id', $headers->get('X-SES-Message-ID')->getBodyAsString());
    }

    public function testSendRawMessageWithExplicitEnvelope(): void
    {
        $message = new RawMessage("From: sender@example.com\r\nTo: recipient@example.com\r\nSubject: Raw mail\r\n\r\nBody");
        $envelope = new Envelope(new Address('sender@example.com'), [new Address('recipient@example.com')]);

        $client = m::mock(SesV2Client::class);
        $client->expects('sendEmail')->with([
            'Source' => 'sender@example.com',
            'Destination' => ['ToAddresses' => ['recipient@example.com']],
            'Content' => ['Raw' => ['Data' => $message->toString()]],
        ])->andReturn(new Result(['MessageId' => 'ses-message-id']));

        $sentMessage = (new SesV2Transport($client))->send($message, $envelope);

        $this->assertInstanceOf(SentMessage::class, $sentMessage);
        $this->assertSame('ses-message-id', $sentMessage->getMessageId());
    }

    public function testSendWithTenantName(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');
        $message->getHeaders()->addTextHeader('X-SES-TENANT-NAME', 'my-tenant');

        $client = m::mock(SesV2Client::class);
        $sesResult = m::mock();
        $sesResult->expects('get')
            ->with('MessageId')
            ->andReturn('ses-message-id');
        $client->expects('sendEmail')
            ->with(m::on(function (array $arg): bool {
                return $arg['TenantName'] === 'my-tenant';
            }))
            ->andReturn($sesResult);

        (new SesV2Transport($client))->send($message);
    }

    public function testSendWithZeroTenantName(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');
        $message->getHeaders()->addTextHeader('X-SES-TENANT-NAME', '0');

        $client = m::mock(SesV2Client::class);
        $sesResult = m::mock();
        $sesResult->expects('get')
            ->with('MessageId')
            ->andReturn('ses-message-id');
        $client->expects('sendEmail')
            ->with(m::on(function (array $arg): bool {
                return $arg['TenantName'] === '0';
            }))
            ->andReturn($sesResult);

        (new SesV2Transport($client))->send($message);
    }

    public function testSendWithoutTenantNameDoesNotSetTheOption(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');

        $client = m::mock(SesV2Client::class);
        $sesResult = m::mock();
        $sesResult->expects('get')
            ->with('MessageId')
            ->andReturn('ses-message-id');
        $client->expects('sendEmail')
            ->with(m::on(function (array $arg): bool {
                return ! array_key_exists('TenantName', $arg);
            }))
            ->andReturn($sesResult);

        (new SesV2Transport($client))->send($message);
    }

    public function testSendWithEmptyTenantNameDoesNotSetTheOption(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');
        $message->getHeaders()->addTextHeader('X-SES-TENANT-NAME', '');

        $client = m::mock(SesV2Client::class);
        $sesResult = m::mock();
        $sesResult->expects('get')
            ->with('MessageId')
            ->andReturn('ses-message-id');
        $client->expects('sendEmail')
            ->with(m::on(function (array $arg): bool {
                return ! array_key_exists('TenantName', $arg);
            }))
            ->andReturn($sesResult);

        (new SesV2Transport($client))->send($message);
    }

    public function testSendError(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');

        $client = m::mock(SesV2Client::class);
        $client->expects('sendEmail')
            ->andThrow(new AwsException('Email address is not verified.', new Command('sendRawEmail')));

        $this->expectException(TransportException::class);

        (new SesV2Transport($client))->send($message);
    }

    public function testSesV2LocalConfiguration(): void
    {
        $this->app->make('config')->set('mail', [
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
        $this->app->make('config')->set('services', [
            'ses' => [
                'region' => 'us-east-1',
            ],
        ]);

        $manager = new MailManager($this->app);

        /** @var Mailer $mailer */
        $mailer = $manager->removePoolableDriver('ses-v2')->mailer('ses');

        /** @var SesV2Transport $transport */
        $transport = $mailer->getSymfonyTransport();

        $this->assertSame('eu-west-1', $transport->ses()->getRegion());

        $this->assertSame([
            'ConfigurationSetName' => 'Hypervel',
            'EmailTags' => [
                ['Name' => 'Hypervel', 'Value' => 'Framework'],
            ],
        ], $transport->getOptions());
    }
}
