<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Support;

use Hypervel\Doctor\Support\Configured;
use Hypervel\Tests\Doctor\TestCase;

class ConfiguredTest extends TestCase
{
    public function testReturnsConfiguredStringValues(): void
    {
        config(['services.example.key' => 'secret']);

        $this->assertSame('secret', Configured::string('services.example.key'));
    }

    public function testFallsBackToTheDefaultForBlankOrNonStringValues(): void
    {
        config([
            'services.example.empty' => '',
            'services.example.array' => ['nested'],
        ]);

        $this->assertNull(Configured::string('services.example.empty'));
        $this->assertNull(Configured::string('services.example.array'));
        $this->assertSame('default', Configured::string('services.example.absent', 'default'));
    }

    public function testReportsConfigurationKeysWithoutAUsableValueAsMissing(): void
    {
        config([
            'services.example.null' => null,
            'services.example.blank' => '  ',
            'services.example.empty-array' => [],
        ]);

        $this->assertSame([
            'services.example.null',
            'services.example.blank',
            'services.example.empty-array',
            'services.example.absent',
        ], Configured::missing([
            'services.example.null',
            'services.example.blank',
            'services.example.empty-array',
            'services.example.absent',
        ]));
    }

    public function testDoesNotReportKeysHoldingUsableValuesAsMissing(): void
    {
        config([
            'services.example.string' => 'value',
            'services.example.false' => false,
            'services.example.zero' => 0,
            'services.example.array' => ['nested'],
        ]);

        $this->assertSame([], Configured::missing([
            'services.example.string',
            'services.example.false',
            'services.example.zero',
            'services.example.array',
        ]));
    }
}
