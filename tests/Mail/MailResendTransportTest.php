<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Exception;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Transport\ResendTransport;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;
use Resend\Contracts\Client;
use Resend\Email as ResendEmail;
use Resend\Service\Email as EmailService;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

class MailResendTransportTest extends TestCase
{
    use VerifiesDoubles;

    public function testGetTransport(): void
    {
        $this->app->make('config')->set('services.resend', [
            'key' => 're_test_123',
        ]);

        $manager = new MailManager($this->app);

        $transport = $manager->createSymfonyTransport(['transport' => 'resend']);

        $this->assertInstanceOf(ResendTransport::class, $transport);
        $this->assertSame('resend', (string) $transport);
    }

    public function testSend(): void
    {
        $message = new Email;
        $message->subject('Test subject');
        $message->text('Test body');
        $message->html('<p>Test body</p>');
        $message->sender('sender@example.com');
        $message->to('recipient@example.com');
        $message->cc('cc@example.com');
        $message->bcc('bcc@example.com');
        $message->replyTo(new Address('reply@example.com', 'Reply'));

        $emails = Double::for(EmailService::class)->strict();
        $emails->expects('send')
            ->with(Argument::satisfies(function (array $arg): bool {
                return $arg['from'] === 'sender@example.com'
                    && $arg['to'] === ['recipient@example.com']
                    && $arg['cc'] === ['cc@example.com']
                    && $arg['bcc'] === ['bcc@example.com']
                    && $arg['reply_to'] === ['"Reply" <reply@example.com>']
                    && $arg['subject'] === 'Test subject'
                    && $arg['html'] === '<p>Test body</p>'
                    && $arg['text'] === 'Test body';
            }))
            ->returns(new ResendEmail(['id' => 'resend-message-id']));

        $client = new class($emails) implements Client {
            /**
             * Create a client backed by the test email service.
             */
            public function __construct(public EmailService $emails)
            {
            }
        };

        $sentMessage = (new ResendTransport($client))->send($message);

        $this->assertSame('resend-message-id', $sentMessage->getMessageId());
        $this->assertSame(
            'resend-message-id',
            $sentMessage->getOriginalMessage()->getHeaders()->get('X-Resend-Email-ID')?->getBodyAsString()
        );
    }

    public function testSendError(): void
    {
        $message = new Email;
        $message->subject('Test subject');
        $message->text('Test body');
        $message->sender('sender@example.com');
        $message->to('recipient@example.com');

        $emails = Double::for(EmailService::class)->strict();
        $emails->expects('send')
            ->throws(new Exception('API key is invalid'));

        $client = new class($emails) implements Client {
            /**
             * Create a client backed by the test email service.
             */
            public function __construct(public EmailService $emails)
            {
            }
        };

        $this->expectException(TransportException::class);
        $this->expectExceptionMessageIs('Request to Resend API failed. Reason: API key is invalid.');

        (new ResendTransport($client))->send($message);
    }
}
