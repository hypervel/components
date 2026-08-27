<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Horizon\Controller;

use Hypervel\Horizon\Contracts\MasterSupervisorRepository;
use Hypervel\Horizon\Contracts\SupervisorRepository;
use Hypervel\Horizon\MasterSupervisor;
use Hypervel\Horizon\Supervisor;
use Hypervel\Horizon\SupervisorOptions;
use Hypervel\Tests\Integration\Horizon\ControllerTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class MasterSupervisorControllerTest extends ControllerTestCase
{
    public function testMasterSupervisorListingWithoutSupervisors()
    {
        $master = new MasterSupervisor;
        $master->name = 'risa';
        resolve(MasterSupervisorRepository::class)->update($master);

        $master2 = new MasterSupervisor;
        $master2->name = 'risa-2';
        resolve(MasterSupervisorRepository::class)->update($master2);

        $response = $this->actingAs(new Fakes\User)->get('/horizon/api/masters');

        $response->assertJson([
            'risa' => ['name' => 'risa', 'status' => 'running'],
            'risa-2' => ['name' => 'risa-2', 'status' => 'running'],
        ]);
    }

    public function testMasterSupervisorListingWithSupervisors()
    {
        $master = new MasterSupervisor;
        $master->name = 'risa';
        resolve(MasterSupervisorRepository::class)->update($master);

        $master2 = new MasterSupervisor;
        $master2->name = 'risa-2';
        resolve(MasterSupervisorRepository::class)->update($master2);

        $supervisor = new Supervisor(new SupervisorOptions('risa:name', 'redis'));
        resolve(SupervisorRepository::class)->update($supervisor);

        $response = $this->actingAs(new Fakes\User)->get('/horizon/api/masters');

        $response->assertJson([
            'risa' => [
                'name' => 'risa',
                'status' => 'running',
                'supervisors' => [
                    [
                        'name' => 'risa:name',
                        'master' => 'risa',
                        'status' => 'running',
                        'processes' => ['redis:default' => 0],
                    ],
                ],
            ],
            'risa-2' => [
                'supervisors' => [],
            ],
        ]);
    }

    public function testMasterSupervisorWithCustomNameListingWithSupervisors()
    {
        $master = new MasterSupervisor;
        $master->name = 'risa:production';
        resolve(MasterSupervisorRepository::class)->update($master);

        $master2 = new MasterSupervisor;
        $master2->name = 'risa:production-2';
        resolve(MasterSupervisorRepository::class)->update($master2);

        $supervisor = new Supervisor(new SupervisorOptions('risa:production:name', 'redis'));
        resolve(SupervisorRepository::class)->update($supervisor);

        $response = $this->actingAs(new Fakes\User)->get('/horizon/api/masters');

        $response->assertJson([
            'risa:production' => [
                'name' => 'risa:production',
                'status' => 'running',
                'supervisors' => [
                    [
                        'name' => 'risa:production:name',
                        'master' => 'risa:production',
                        'status' => 'running',
                        'processes' => ['redis:default' => 0],
                    ],
                ],
            ],
            'risa:production-2' => [
                'supervisors' => [],
            ],
        ]);
    }

    #[DataProvider('environmentProvider')]
    public function testInactiveSupervisorsUseTheSelectedEnvironment(
        ?string $horizonEnvironment,
        string $expectedQueue,
    ): void {
        config()->set([
            'app.env' => 'application',
            'horizon.env' => $horizonEnvironment,
            'horizon.environments' => [
                'application' => [
                    'supervisor-1' => ['queue' => ['application']],
                ],
                'horizon' => [
                    'supervisor-1' => ['queue' => ['horizon']],
                ],
            ],
        ]);

        $master = new MasterSupervisor;
        $master->name = 'risa';
        resolve(MasterSupervisorRepository::class)->update($master);

        $response = $this->actingAs(new Fakes\User)
            ->get('/horizon/api/masters');

        $response
            ->assertJsonPath('risa.supervisors.0.status', 'inactive')
            ->assertJsonPath('risa.supervisors.0.options.queue', $expectedQueue);
    }

    /**
     * Provide Horizon environment selection cases.
     */
    public static function environmentProvider(): array
    {
        return [
            'application environment' => [null, 'application'],
            'Horizon environment' => ['horizon', 'horizon'],
        ];
    }
}
