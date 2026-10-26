<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Support;

use Hypervel\Doctor\EnvironmentMode;
use Hypervel\Tests\Doctor\TestCase;
use InvalidArgumentException;

class EnvironmentModeTest extends TestCase
{
    public function testResolvesConfiguredEnvironmentModes(): void
    {
        config(['doctor.environments' => [
            'local' => ['dev'],
            'production' => ['staging'],
        ]]);

        $this->assertSame(EnvironmentMode::Local, EnvironmentMode::fromHypervelEnvironment('dev'));
        $this->assertSame(EnvironmentMode::Production, EnvironmentMode::fromHypervelEnvironment('staging'));
    }

    public function testTreatsUnmappedEnvironmentsAsProduction(): void
    {
        $this->assertSame(EnvironmentMode::Production, EnvironmentMode::fromHypervelEnvironment('preview'));
    }

    public function testRejectsInvalidConfiguredEnvironmentModes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Invalid Doctor environment mode [lcoal] configured.');
        config(['doctor.environments' => ['lcoal' => ['local']]]);

        EnvironmentMode::fromHypervelEnvironment('local');
    }

    public function testRejectsEnvironmentNamesThatAreNotConfiguredAsArrays(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Hypervel environment names for Doctor mode [local] must be an array, [string] given.');
        config(['doctor.environments' => ['local' => 'local']]);

        EnvironmentMode::fromHypervelEnvironment('local');
    }

    public function testRejectsEnvironmentNamesAssignedToMultipleModes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageIsOrContains('Hypervel environment [shared] is configured for multiple Doctor modes.');
        config(['doctor.environments' => [
            'local' => ['shared'],
            'production' => ['shared'],
        ]]);

        EnvironmentMode::fromHypervelEnvironment('shared');
    }
}
