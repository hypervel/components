<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Contracts\Config\Repository;
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
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->set(ViewFactory::class, m::mock(ViewFactory::class));
    }

    public function testGetFailoverTransportWithConfiguredTransports()
    {
        $this->app->get(Repository::class)->set('mail', [
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

        $transport = $this->app->get(FactoryContract::class)
            ->removePoolable('failover')
            ->getSymfonyTransport();
        $this->assertInstanceOf(FailoverTransport::class, $transport);
    }

    public function testGetFailoverTransportWithLaravel6StyleMailConfiguration()
    {
        $this->app->get(Repository::class)->set('mail', [
            'driver' => 'failover',
            'mailers' => ['sendmail', 'array'],
            'sendmail' => '/usr/sbin/sendmail -bs',
        ]);

        $transport = $this->app->get(FactoryContract::class)
            ->removePoolable('failover')
            ->getSymfonyTransport();
        $this->assertInstanceOf(FailoverTransport::class, $transport);
    }
}
