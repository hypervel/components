<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hyperf\Contract\ConfigInterface;
use Hyperf\ViewEngine\Contract\FactoryInterface as ViewFactory;
use Hypervel\Contracts\Mail\Factory as FactoryContract;
use Hypervel\Mail\Attachment;
use Hypervel\Mail\Message;
use Hypervel\Mail\Transport\LogTransport;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Stringable;
use Symfony\Component\Mime\Email;

/**
 * @internal
 * @coversNothing
 */
class MailLogTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->set(ViewFactory::class, m::mock(ViewFactory::class));
    }

    public function testGetLogTransportWithConfiguredChannel()
    {
        $this->app->get(ConfigInterface::class)->set('mail', [
            'driver' => 'log',
            'log_channel' => 'mail',
        ]);
        $this->app->get(ConfigInterface::class)->set('logging', [
            'channels' => [
                'mail' => [
                    'driver' => 'single',
                    'path' => 'mail.log',
                ],
            ],
        ]);

        $transport = $this->app->get(FactoryContract::class)
            ->removePoolable('log')
            ->getSymfonyTransport();
        $this->assertInstanceOf(LogTransport::class, $transport);

        $logger = $transport->logger();
        $this->assertInstanceOf(LoggerInterface::class, $logger);
    }

    public function testItDecodesTheMessageBeforeLogging()
    {
        $message = (new Message(new Email()))
            ->from('noreply@example.com', 'no-reply')
            ->to('taylor@example.com', 'Taylor')
            ->html(<<<'BODY'
            Hi,

            <a href="https://example.com/reset-password=5e113c71a4c210aff04b3fa66f1b1299">Click here to reset your password</a>.

            All the best,

            Burt & Irving
            BODY)
            ->text('A text part');

        $actualLoggedValue = $this->getLoggedEmailMessage($message);

        $this->assertStringNotContainsString("=\r\n", $actualLoggedValue);
        $this->assertStringContainsString('href=', $actualLoggedValue);
        $this->assertStringContainsString('Burt & Irving', $actualLoggedValue);
        $this->assertStringContainsString('https://example.com/reset-password=5e113c71a4c210aff04b3fa66f1b1299', $actualLoggedValue);
    }

    public function testItOnlyDecodesQuotedPrintablePartsOfTheMessageBeforeLogging()
    {
        $message = (new Message(new Email()))
            ->from('noreply@example.com', 'no-reply')
            ->to('taylor@example.com', 'Taylor')
            ->html(<<<'BODY'
            Hi,

            <a href="https://example.com/reset-password=5e113c71a4c210aff04b3fa66f1b1299">Click here to reset your password</a>.

            All the best,

            Burt & Irving
            BODY)
            ->text('A text part')
            ->attach(Attachment::fromData(fn () => 'My attachment', 'attachment.txt'));

        $actualLoggedValue = $this->getLoggedEmailMessage($message);

        $this->assertStringContainsString('href=', $actualLoggedValue);
        $this->assertStringContainsString('Burt & Irving', $actualLoggedValue);
        $this->assertStringContainsString('https://example.com/reset-password=5e113c71a4c210aff04b3fa66f1b1299', $actualLoggedValue);
        $this->assertStringContainsString('name=attachment.txt', $actualLoggedValue);
        $this->assertStringContainsString('filename=attachment.txt', $actualLoggedValue);
    }

    public function testGetLogTransportWithPsrLogger()
    {
        $this->app->get(ConfigInterface::class)->set('mail', [
            'driver' => 'log',
        ]);

        $this->app->set(LoggerInterface::class, new NullLogger());

        $transportLogger = $this->app->get(FactoryContract::class)->getSymfonyTransport()->logger();

        $this->assertEquals(
            $this->app->get(LoggerInterface::class),
            $transportLogger
        );
    }

    private function getLoggedEmailMessage(Message $message): string
    {
        $logger = new class extends NullLogger {
            public string $loggedValue = '';

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->loggedValue = (string) $message;
            }
        };

        (new LogTransport($logger))->send(
            $message->getSymfonyMessage()
        );

        return $logger->loggedValue;
    }
}
