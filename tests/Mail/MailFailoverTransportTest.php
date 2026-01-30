<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hyperf\Contract\ConfigInterface;
use Hyperf\ViewEngine\Contract\FactoryInterface as ViewFactory;
use Hypervel\Contracts\Mail\Factory as FactoryContract;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Mailer\Transport\FailoverTransport;

/**
 * @internal
 * @coversNothing
 */
class MailFailoverTransportTest extends TestCase
{
    public function testGetFailoverTransportWithConfiguredTransports()
    {
        $container = $this->getContainer([
            'default' => 'failover',
            'mailers' => [
                'failover' => [
                    'transport' => 'failover',
                    'mailers' => [
                        'sendmail',
                        'array',
                    ],
                ],

                'sendmail' => [
                    'transport' => 'sendmail',
                    'path' => '/usr/sbin/sendmail -bs',
                ],

                'array' => [
                    'transport' => 'array',
                ],
            ],
        ]);

        $transport = $container->get(FactoryContract::class)
            ->removePoolable('failover')
            ->getSymfonyTransport();
        $this->assertInstanceOf(FailoverTransport::class, $transport);
    }

    public function testGetFailoverTransportWithLaravel6StyleMailConfiguration()
    {
        $container = $this->getContainer([
            'driver' => 'failover',
            'mailers' => ['sendmail', 'array'],
            'sendmail' => '/usr/sbin/sendmail -bs',
        ]);

        $transport = $container->get(FactoryContract::class)
            ->removePoolable('failover')
            ->getSymfonyTransport();
        $this->assertInstanceOf(FailoverTransport::class, $transport);
    }

    protected function getContainer(array $config = []): ContainerInterface
    {
        $container = new Container(
            new DefinitionSource([
                ConfigInterface::class => fn () => new Config(['mail' => $config]),
                FactoryContract::class => MailManager::class,
                ViewInterface::class => fn () => Mockery::mock(ViewInterface::class),
                EventDispatcherInterface::class => fn () => Mockery::mock(EventDispatcherInterface::class),
            ])
        );

        ApplicationContext::setContainer($container);

        return $container;
    }
}
