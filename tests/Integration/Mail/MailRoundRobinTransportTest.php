<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Mail;

use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Testbench\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;

class MailRoundRobinTransportTest extends TestCase
{
    use VerifiesDoubles;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance('view', Double::for(ViewFactory::class)->strict());
    }

    public function testGetRoundRobinTransportWithConfiguredTransports(): void
    {
        config(['mail' => [
            'default' => 'roundrobin',
            'mailers' => [
                'roundrobin' => [
                    'transport' => 'roundrobin',
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
            ->removePoolableDriver('roundrobin')
            ->getSymfonyTransport();
        $this->assertInstanceOf(RoundRobinTransport::class, $transport);
    }

    // REMOVED: Laravel 6-style mail configuration; Hypervel uses named mail.mailers entries.
}
