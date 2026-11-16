<?php

declare(strict_types=1);

namespace Hypervel\Tests\Events\EventsSubscriberTest;

use Hypervel\Events\Dispatcher;
use Hypervel\Tests\TestCase;
use JMac\Testing\Double;
use JMac\Testing\Integrations\PHPUnit\VerifiesDoubles;
use JMac\Testing\Matching\Argument;

class EventsSubscriberTest extends TestCase
{
    use VerifiesDoubles;

    public function testEventSubscribeCanAcceptObject(): void
    {
        $d = new Dispatcher;
        $subs = Double::for(ExampleSubscriber::class);
        $subs->expects('subscribe')->with(Argument::same($d));

        $d->subscribe($subs);
    }

    public function testEventSubscribeCanReturnMappings(): void
    {
        $d = new Dispatcher;
        $d->subscribe(DeclarativeSubscriber::class);

        $d->dispatch('myEvent1');
        $this->assertSame('L1_L2_', DeclarativeSubscriber::$string);

        $d->dispatch('myEvent2');
        $this->assertSame('L1_L2_L3', DeclarativeSubscriber::$string);
    }
}

class ExampleSubscriber
{
    public function subscribe($e)
    {
        // There would be no error if a non-array is returned.
        return '(O_o)';
    }
}

class DeclarativeSubscriber
{
    public static string $string = '';

    public function subscribe()
    {
        return [
            'myEvent1' => [
                self::class . '@listener1',
                self::class . '@listener2',
            ],
            'myEvent2' => [
                self::class . '@listener3',
            ],
        ];
    }

    public function listener1()
    {
        self::$string .= 'L1_';
    }

    public function listener2()
    {
        self::$string .= 'L2_';
    }

    public function listener3()
    {
        self::$string .= 'L3';
    }
}
