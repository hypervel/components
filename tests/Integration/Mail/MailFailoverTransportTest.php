<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use ReflectionProperty;
use Symfony\Component\Mailer\Transport\FailoverTransport;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;

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

    public function testGetFailoverTransportWithConfiguredRetryPeriod(): void
    {
        config(['mail' => [
            'default' => 'failover',
            'mailers' => [
                'failover' => [
                    'transport' => 'failover',
                    'mailers' => ['sendmail', 'array'],
                    'retry_after' => 5,
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

        $this->assertSame(5, (new ReflectionProperty(RoundRobinTransport::class, 'retryPeriod'))->getValue($transport));
    }

    // REMOVED: Laravel 6-style mail configuration; Hypervel uses named mail.mailers entries.
}
