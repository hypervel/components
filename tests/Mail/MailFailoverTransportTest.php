<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Contracts\View\Factory as ViewFactory;
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
        $this->app->instance('view', m::mock(ViewFactory::class));
    }

    public function testGetFailoverTransportWithConfiguredTransports()
    {
        $this->app->make('config')->set('mail', [
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

        $transport = $this->app->make('mail.manager')
            ->removePoolable('failover')
            ->getSymfonyTransport();
        $this->assertInstanceOf(FailoverTransport::class, $transport);
    }

    public function testGetFailoverTransportWithConfiguredTransportsUsingDefaultMailer()
    {
        $this->app->make('config')->set('mail', [
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

        $transport = $this->app->make('mail.manager')
            ->removePoolable('failover')
            ->getSymfonyTransport();
        $this->assertInstanceOf(FailoverTransport::class, $transport);
    }
}
