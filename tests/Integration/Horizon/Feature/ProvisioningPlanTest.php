<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Feature;

use Hypervel\Contracts\Events\Dispatcher;
use Hypervel\Horizon\Events\MasterSupervisorDeployed;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\MasterSupervisorCommands\AddSupervisor;
use Hypervel\Horizon\ProvisioningPlan;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\Redis;
use Hypervel\Tests\Integration\Horizon\IntegrationTestCase;

class ProvisioningPlanTest extends IntegrationTestCase
{
    public function testGetUsesNoSharedOptionsWhenDefaultsAreOmitted(): void
    {
        $horizonConfig = config()->array('horizon');
        $supervisor = $horizonConfig['defaults']['supervisor-1'];
        unset($horizonConfig['defaults']);
        $horizonConfig['environments'] = [
            'local' => ['supervisor-1' => $supervisor],
        ];
        config()->set('horizon', $horizonConfig);

        $options = ProvisioningPlan::get(MasterSupervisor::name())
            ->optionsFor('local', 'supervisor-1');

        $this->assertNotNull($options);
        $this->assertSame('redis', $options->connection);
        $this->assertSame('default', $options->queue);
    }

    public function testSupervisorsAreAdded()
    {
        Event::fake([MasterSupervisorDeployed::class]);

        $plan = [
            'production' => [
                'supervisor-1' => [
                    'connection' => 'redis',
                    'queue' => 'first',
                    'max_processes' => 20,
                ],
            ],
        ];

        $plan = new ProvisioningPlan(MasterSupervisor::name(), $plan);
        $plan->deploy('production');

        $commands = Redis::connection('horizon')->lRange(
            'commands:' . MasterSupervisor::commandQueueFor(MasterSupervisor::name()),
            0,
            -1
        );

        $this->assertCount(1, $commands);
        $command = (object) json_decode($commands[0], true);
        $this->assertSame(AddSupervisor::class, $command->command);
        $this->assertSame('first', $command->options['queue']);
        $this->assertSame(20, $command->options['maxProcesses']);
        Event::assertDispatched(
            fn (MasterSupervisorDeployed $event): bool => $event->master === MasterSupervisor::name()
        );
    }

    public function testPassiveObserverDoesNotCauseDeploymentEventToDispatch(): void
    {
        $observedEvents = [];
        $this->app->make(Dispatcher::class)->observe(
            MasterSupervisorDeployed::class,
            static function (MasterSupervisorDeployed $event) use (&$observedEvents): void {
                $observedEvents[] = $event;
            }
        );

        $plan = [
            'production' => [
                'supervisor-1' => [
                    'connection' => 'redis',
                    'queue' => 'first',
                    'max_processes' => 20,
                ],
            ],
        ];

        (new ProvisioningPlan(MasterSupervisor::name(), $plan))->deploy('production');

        $commands = Redis::connection('horizon')->lRange(
            'commands:' . MasterSupervisor::commandQueueFor(MasterSupervisor::name()),
            0,
            -1
        );

        $this->assertCount(1, $commands);
        $this->assertSame([], $observedEvents);
    }

    public function testSupervisorsAreAddedAsFallbackForWildcardEnvironments()
    {
        $plan = [
            '*' => [
                'supervisor-1' => [
                    'connection' => 'redis',
                    'queue' => 'first',
                    'max_processes' => 10,
                ],
            ],
        ];

        $plan = new ProvisioningPlan(MasterSupervisor::name(), $plan);
        $plan->deploy('develop');

        $commands = Redis::connection('horizon')->lRange(
            'commands:' . MasterSupervisor::commandQueueFor(MasterSupervisor::name()),
            0,
            -1
        );

        $this->assertCount(1, $commands);
        $command = (object) json_decode($commands[0], true);
        $this->assertSame(AddSupervisor::class, $command->command);
        $this->assertSame('first', $command->options['queue']);
        $this->assertSame(10, $command->options['maxProcesses']);
    }

    public function testPlanIsConvertedIntoArrayOfSupervisorOptions()
    {
        $plan = [
            'production' => [
                'supervisor-1' => [
                    'connection' => 'redis',
                    'queue' => 'default',
                    'balance' => true,
                    'auto_scale' => true,
                ],

                'supervisor-2' => [
                    'connection' => 'redis',
                    'queue' => 'default',
                ],
            ],

            'local' => [
                'supervisor-2' => [
                    'connection' => 'redis',
                    'queue' => 'local-supervisor-2-queue',
                    'max_processes' => 20,
                ],
            ],
        ];

        $results = (new ProvisioningPlan(MasterSupervisor::name(), $plan))->toSupervisorOptions();

        $this->assertSame(MasterSupervisor::name() . ':supervisor-1', $results['production']['supervisor-1']->name);
        $this->assertSame('redis', $results['production']['supervisor-1']->connection);
        $this->assertSame('default', $results['production']['supervisor-1']->queue);
        $this->assertTrue($results['production']['supervisor-1']->balance);
        $this->assertTrue($results['production']['supervisor-1']->autoScale);

        $this->assertSame(20, $results['local']['supervisor-2']->maxProcesses);
    }

    public function testBackoffIsTranslatedToStringForm()
    {
        $plan = [
            'local' => [
                'supervisor-2' => [
                    'connection' => 'redis',
                    'queue' => 'local-supervisor-2-queue',
                    'backoff' => [30, 60],
                ],
            ],
        ];

        $results = (new ProvisioningPlan(MasterSupervisor::name(), $plan))->toSupervisorOptions();

        $this->assertSame('30,60', $results['local']['supervisor-2']->backoff);
    }
}
