<?php

declare(strict_types=1);

namespace Hypervel\Tests\Horizon\Unit;

use Hypervel\Horizon\Horizon;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\SystemProcessCounter;
use Hypervel\Tests\Horizon\UnitTestCase;
use ReflectionClass;

class StaticStateTest extends UnitTestCase
{
    public function testHorizonFlushStateClearsNotificationAndAuthConfiguration()
    {
        Horizon::auth(fn () => true);
        Horizon::routeMailNotificationsTo('test@example.com');
        Horizon::routeSlackNotificationsTo('https://example.com/webhook', '#alerts');
        Horizon::routeSmsNotificationsTo('+15555555555');

        $this->assertNotNull(Horizon::$authUsing);
        $this->assertSame('test@example.com', Horizon::$email);
        $this->assertSame('https://example.com/webhook', Horizon::$slackWebhookUrl);
        $this->assertSame('#alerts', Horizon::$slackChannel);
        $this->assertSame('+15555555555', Horizon::$smsNumber);

        Horizon::flushState();

        $this->assertNull(Horizon::$authUsing);
        $this->assertNull(Horizon::$email);
        $this->assertNull(Horizon::$slackWebhookUrl);
        $this->assertNull(Horizon::$slackChannel);
        $this->assertNull(Horizon::$smsNumber);
    }

    public function testMasterSupervisorFlushStateClearsNameResolverAndToken()
    {
        $token = (new ReflectionClass(MasterSupervisor::class))->getProperty('token');

        MasterSupervisor::determineNameUsing(fn () => 'test-name');
        MasterSupervisor::name();

        $this->assertNotNull(MasterSupervisor::$nameResolver);
        $this->assertNotNull($token->getValue());

        MasterSupervisor::flushState();

        $this->assertNull(MasterSupervisor::$nameResolver);
        $this->assertNull($token->getValue());
    }

    public function testSystemProcessCounterFlushStateRestoresCommand()
    {
        SystemProcessCounter::$command = 'worker.php';

        $this->assertSame('worker.php', SystemProcessCounter::$command);

        SystemProcessCounter::flushState();

        $this->assertSame('horizon:work', SystemProcessCounter::$command);
    }
}
