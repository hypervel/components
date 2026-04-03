<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Exception;
use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Transport\ResendTransport;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Resend\Contracts\Client;
use Resend\Email as ResendEmail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * @internal
 * @coversNothing
 */
class MailResendTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('view', m::mock(ViewFactory::class));
    }

    public function testGetTransport()
    {
        $this->app->make('config')->set('services.resend', [
            'key' => 're_test_123',
        ]);

        $manager = new MailManager($this->app);

        $transport = $manager->createSymfonyTransport(['transport' => 'resend']);

        $this->assertInstanceOf(ResendTransport::class, $transport);
        $this->assertSame('resend', (string) $transport);
    }

    public function testSend()
    {
        $message = new Email();
        $message->subject('Test subject');
        $message->text('Test body');
        $message->html('<p>Test body</p>');
        $message->sender('sender@example.com');
        $message->to('recipient@example.com');
        $message->cc('cc@example.com');
        $message->bcc('bcc@example.com');
        $message->replyTo(new Address('reply@example.com', 'Reply'));

        $emails = m::mock();
        $emails->shouldReceive('send')->once()
            ->with(m::on(function ($arg) {
                return $arg['from'] === 'sender@example.com'
                    && $arg['to'] === ['recipient@example.com']
                    && $arg['cc'] === ['cc@example.com']
                    && $arg['bcc'] === ['bcc@example.com']
                    && $arg['reply_to'] === ['"Reply" <reply@example.com>']
                    && $arg['subject'] === 'Test subject'
                    && $arg['html'] === '<p>Test body</p>'
                    && $arg['text'] === 'Test body';
            }))
            ->andReturn(new ResendEmail(['id' => 'resend-message-id']));

        $client = m::mock(Client::class);
        $client->emails = $emails;

        (new ResendTransport($client))->send($message);
    }

    public function testSendError()
    {
        $message = new Email();
        $message->subject('Test subject');
        $message->text('Test body');
        $message->sender('sender@example.com');
        $message->to('recipient@example.com');

        $emails = m::mock();
        $emails->shouldReceive('send')->once()
            ->andThrow(new Exception('API key is invalid'));

        $client = m::mock(Client::class);
        $client->emails = $emails;

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('Request to Resend API failed. Reason: API key is invalid.');

        (new ResendTransport($client))->send($message);
    }
}
