<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hyperf\Config\Config;
use Hyperf\Context\ApplicationContext;
use Hyperf\Contract\ConfigInterface;
use Hyperf\Di\Container;
use Hyperf\Di\Definition\DefinitionSource;
use Hyperf\ViewEngine\Contract\FactoryInterface as ViewInterface;
use Hypervel\Contracts\Mail\Factory as FactoryContract;
use Hypervel\Mail\Attachment;
use Hypervel\Mail\MailManager;
use Hypervel\Mail\Message;
use Hypervel\Mail\Transport\LogTransport;
use Mockery;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
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
    public function testGetLogTransportWithConfiguredChannel()
    {
        $container = $this->getContainer([
            'mail' => [
                'driver' => 'log',
                'log_channel' => 'mail',
            ],
            'logging' => [
                'channels' => [
                    'mail' => [
                        'driver' => 'single',
                        'path' => 'mail.log',
                    ],
                ],
            ],
        ]);

        $transport = $container->get(FactoryContract::class)
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
        $container = $this->getContainer([
            'mail' => [
                'driver' => 'log',
            ],
        ]);

        /** @var Container $container */
        $container->set(LoggerInterface::class, new NullLogger());

        $transportLogger = $container->get(FactoryContract::class)->getSymfonyTransport()->logger();

        $this->assertEquals(
            $container->get(LoggerInterface::class),
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

    protected function getContainer(array $config = []): ContainerInterface
    {
        $container = new Container(
            new DefinitionSource([
                ConfigInterface::class => fn () => new Config($config),
                FactoryContract::class => MailManager::class,
                ViewInterface::class => fn () => Mockery::mock(ViewInterface::class),
                EventDispatcherInterface::class => fn () => Mockery::mock(EventDispatcherInterface::class),
                LoggerInterface::class => fn () => Mockery::mock(LoggerInterface::class),
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }
}
