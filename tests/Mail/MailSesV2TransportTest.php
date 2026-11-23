<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Aws\Command;
use Aws\Credentials\Credentials;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\SesV2\SesV2Client;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Promise\PromiseInterface;
use Hypervel\Mail\Mailer;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Transport\SesV2Transport;
use Hypervel\Testbench\TestCase;
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

    public function testCallableCredentialsTakePrecedenceOverServiceKeys(): void
    {
        config(['services.ses' => [
            'key' => 'base-key',
            'secret' => 'base-secret',
            'region' => 'us-east-1',
        ]]);

        $transport = (new MailManager($this->app))->createSymfonyTransport([
            'transport' => 'ses-v2',
            'credentials' => static fn (): PromiseInterface => Create::promiseFor(new Credentials('role-key', 'role-secret')),
        ]);

        $this->assertSame('role-key', $transport->ses()->getCredentials()->wait()->getAccessKeyId());
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

        $handler = new MockHandler([new Result(['MessageId' => 'ses-message-id'])]);

        $sentMessage = (new SesV2Transport($this->sesClient($handler)))->send($message);

        $this->assertSame('SendEmail', $handler->getLastCommand()->getName());
        $parameters = json_decode((string) $handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(['me@example.com', 'you@example.com'], $parameters['Destination']['ToAddresses']);
        $this->assertSame(['ContactListName' => 'TestList', 'TopicName' => 'TestTopic'], $parameters['ListManagementOptions']);
        $this->assertSame([['Name' => 'FooTag', 'Value' => 'TagValue']], $parameters['EmailTags']);
        $raw = base64_decode($parameters['Content']['Raw']['Data']);
        $this->assertStringContainsString('Sender: myself@example.com', $raw);
        $this->assertStringContainsString('Reply-To: Taylor Otwell <taylor@example.com>', $raw);

        $this->assertSame('mime-message-id@example.com', $sentMessage->getMessageId());
        $headers = $sentMessage->getOriginalMessage()->getHeaders();
        $this->assertSame('ses-message-id', $headers->get('X-Message-ID')->getBodyAsString());
        $this->assertSame('ses-message-id', $headers->get('X-SES-Message-ID')->getBodyAsString());
    }

    public function testSendRawMessageWithExplicitEnvelope(): void
    {
        $message = new RawMessage("From: sender@example.com\r\nReturn-Path: <bounce@example.com>\r\nTo: recipient@example.com\r\nSubject: Raw mail\r\n\r\nBody");
        $envelope = new Envelope(new Address('bounce@example.com'), [new Address('recipient@example.com')]);
        $handler = new MockHandler([new Result(['MessageId' => 'ses-message-id'])]);

        $sentMessage = (new SesV2Transport($this->sesClient($handler)))->send($message, $envelope);

        $parameters = json_decode((string) $handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame([
            'Destination' => ['ToAddresses' => ['recipient@example.com']],
            'Content' => ['Raw' => ['Data' => base64_encode($message->toString())]],
        ], $parameters);
        $this->assertInstanceOf(SentMessage::class, $sentMessage);
        $this->assertSame('ses-message-id', $sentMessage->getMessageId());
    }

    public function testExplicitSenderOptionsReachSes(): void
    {
        $message = (new Email)->from('sender@example.com')->to('recipient@example.com')->text('Body');
        $handler = new MockHandler([new Result(['MessageId' => 'ses-message-id'])]);
        $transport = new SesV2Transport($this->sesClient($handler), [
            'FromEmailAddress' => 'identity@example.com',
            'FeedbackForwardingEmailAddress' => 'feedback@example.com',
        ]);

        $transport->send($message);

        $parameters = json_decode((string) $handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('identity@example.com', $parameters['FromEmailAddress']);
        $this->assertSame('feedback@example.com', $parameters['FeedbackForwardingEmailAddress']);
        $this->assertStringContainsString('From: sender@example.com', base64_decode($parameters['Content']['Raw']['Data']));
    }

    public function testSendWithTenantName(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');
        $message->getHeaders()->addTextHeader('X-SES-TENANT-NAME', 'my-tenant');

        $handler = new MockHandler([new Result(['MessageId' => 'ses-message-id'])]);

        (new SesV2Transport($this->sesClient($handler)))->send($message);

        $parameters = json_decode((string) $handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('my-tenant', $parameters['TenantName']);
    }

    public function testSendWithZeroTenantName(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');
        $message->getHeaders()->addTextHeader('X-SES-TENANT-NAME', '0');

        $handler = new MockHandler([new Result(['MessageId' => 'ses-message-id'])]);

        (new SesV2Transport($this->sesClient($handler)))->send($message);

        $parameters = json_decode((string) $handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('0', $parameters['TenantName']);
    }

    public function testSendWithoutTenantNameDoesNotSetTheOption(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');

        $handler = new MockHandler([new Result(['MessageId' => 'ses-message-id'])]);

        (new SesV2Transport($this->sesClient($handler)))->send($message);

        $parameters = json_decode((string) $handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('TenantName', $parameters);
    }

    public function testSendWithEmptyTenantNameDoesNotSetTheOption(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');
        $message->getHeaders()->addTextHeader('X-SES-TENANT-NAME', '');

        $handler = new MockHandler([new Result(['MessageId' => 'ses-message-id'])]);

        (new SesV2Transport($this->sesClient($handler)))->send($message);

        $parameters = json_decode((string) $handler->getLastRequest()->getBody(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertArrayNotHasKey('TenantName', $parameters);
    }

    public function testSendError(): void
    {
        $message = new Email;
        $message->subject('Foo subject');
        $message->text('Bar body');
        $message->sender('myself@example.com');
        $message->to('me@example.com');

        $handler = new MockHandler;
        $handler->append(new AwsException('Email address is not verified.', new Command('sendEmail')));

        $this->expectException(TransportException::class);

        (new SesV2Transport($this->sesClient($handler)))->send($message);
    }

    /**
     * Create an SES client using the in-memory handler.
     */
    protected function sesClient(MockHandler $handler): SesV2Client
    {
        return new SesV2Client([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'foo', 'secret' => 'bar'],
            'handler' => $handler,
        ]);
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
