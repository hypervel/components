<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Aws\Command;
use Aws\Exception\AwsException;
use Aws\SesV2\SesV2Client;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Transport\SesV2Transport;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailSesV2TransportTest extends TestCase
{
    // REMOVED: Laravel's SES v1 transport tests. Hypervel supports SES v2 only.

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

        /** @var \Hypervel\Mail\Transport\SesV2Transport $transport */
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
        $message->getHeaders()->add(new MetadataHeader('FooTag', 'TagValue'));
        $message->getHeaders()->addTextHeader('X-SES-LIST-MANAGEMENT-OPTIONS', 'contactListName=TestList;topicName=TestTopic');

        $client = m::mock(SesV2Client::class);
        $sesResult = m::mock();
        $sesResult->shouldReceive('get')
            ->with('MessageId')
            ->once()
            ->andReturn('ses-message-id');
        $client->shouldReceive('sendEmail')->once()
            ->with(m::on(function (array $arg): bool {
                return $arg['Source'] === 'myself@example.com'
                    && $arg['Destination']['ToAddresses'] === ['me@example.com', 'you@example.com']
                    && $arg['ListManagementOptions'] === ['ContactListName' => 'TestList', 'TopicName' => 'TestTopic']
                    && $arg['EmailTags'] === [['Name' => 'FooTag', 'Value' => 'TagValue']]
                    && str_contains($arg['Content']['Raw']['Data'], 'Reply-To: Taylor Otwell <taylor@example.com>');
            }))
            ->andReturn($sesResult);

        (new SesV2Transport($client))->send($message);
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
        $sesResult->shouldReceive('get')
            ->with('MessageId')
            ->once()
            ->andReturn('ses-message-id');
        $client->shouldReceive('sendEmail')->once()
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
        $sesResult->shouldReceive('get')
            ->with('MessageId')
            ->once()
            ->andReturn('ses-message-id');
        $client->shouldReceive('sendEmail')->once()
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
        $sesResult->shouldReceive('get')
            ->with('MessageId')
            ->once()
            ->andReturn('ses-message-id');
        $client->shouldReceive('sendEmail')->once()
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
        $sesResult->shouldReceive('get')
            ->with('MessageId')
            ->once()
            ->andReturn('ses-message-id');
        $client->shouldReceive('sendEmail')->once()
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
        $client->shouldReceive('sendEmail')->once()
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

        /** @var \Hypervel\Mail\Mailer $mailer */
        $mailer = $manager->removePoolable('ses-v2')->mailer('ses');

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
}
