<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mail;

use Hypervel\Contracts\View\Factory as ViewFactory;
use Hypervel\Testbench\TestCase;
use Mockery as m;
use Symfony\Component\Mailer\Transport\RoundRobinTransport;

/**
 * @internal
 * @coversNothing
 */
class MailRoundRobinTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app->instance(ViewFactory::class, m::mock(ViewFactory::class));
    }

    public function testGetRoundRobinTransportWithConfiguredTransports()
    {
        $this->app->make('config')->set('mail', [
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
        ]);

        $transport = $this->app->make('mail.manager')
            ->removePoolable('roundrobin')
            ->getSymfonyTransport();
        $this->assertInstanceOf(RoundRobinTransport::class, $transport);
    }
}
