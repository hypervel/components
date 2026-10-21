<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Mailer\Transport\FailoverTransport;

class MailFailoverTransportTest extends TestCase
{
    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('view', m::mock(ViewFactory::class));
    }

    public function testGetFailoverTransportWithConfiguredTransports(): void
    {
        config(['mail' => [
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
        ]]);

        $transport = $this->app->make('mail.manager')
            ->removePoolableDriver('failover')
            ->getSymfonyTransport();
        $this->assertInstanceOf(FailoverTransport::class, $transport);
    }

    // REMOVED: Laravel 6-style mail configuration; Hypervel uses named mail.mailers entries.
}
