<?php

declare(strict_types=1);

namespace Hypervel\Tests\Doctor\Feature\Diagnostics;

use Hypervel\Doctor\Diagnostics\ConfigurationCanBeCached;
use Hypervel\Doctor\Results\Link;
use Hypervel\Doctor\Results\Status;
use Hypervel\Tests\Doctor\TestCase;
use RuntimeException;

class ConfigurationCanBeCachedTest extends TestCase
{
    public function testReportsConfigurationValuesThatCannotBeCached(): void
    {
        config(['doctor.unserializable' => fn (): bool => true]);

        $result = (new ConfigurationCanBeCached)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('doctor.unserializable', $result->details);
        $this->assertEquals([Link::docs('configuration', 'configuration-caching')], $result->links);
    }

    public function testAcceptsExportableObjectsEnumsAndNestedConfiguration(): void
    {
        config(['doctor.values' => [
            'object' => new DoctorExportableConfig('value'),
            'enum' => Status::Pass,
            'nested' => ['value' => true],
        ]]);

        $result = (new ConfigurationCanBeCached)->check();

        $this->assertSame('pass', $result->status->value, $result->details ?? '');
    }

    public function testReportsFailuresFromObjectStateRestoration(): void
    {
        config(['doctor.values' => new DoctorThrowingConfig]);

        $result = (new ConfigurationCanBeCached)->check();

        $this->assertSame('fail', $result->status->value);
        $this->assertStringContainsString('Cannot restore this configuration value.', $result->details);
    }
}

class DoctorExportableConfig
{
    /**
     * Create an exportable configuration value.
     */
    public function __construct(public string $value)
    {
    }

    /**
     * Restore an exported configuration value.
     */
    public static function __set_state(array $properties): self
    {
        return new self($properties['value']);
    }
}

class DoctorThrowingConfig
{
    /**
     * Reject restoration of the exported value.
     */
    public static function __set_state(array $properties): self
    {
        throw new RuntimeException('Cannot restore this configuration value.');
    }
}
