<?php

declare(strict_types=1);

namespace Hypervel\Tests\Mcp\Unit\Schema;

use Hypervel\Mcp\Schema\Implementation;
use Hypervel\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ImplementationTest extends TestCase
{
    public function testParsesAWellFormedImplementation(): void
    {
        $implementation = Implementation::from([
            'name' => 'Test Server',
            'version' => '1.0.0',
            'title' => 'Testing',
            'icons' => [['src' => 'https://mcp.test/icon.png', 'theme' => 'dark']],
        ]);

        $this->assertSame('Test Server', $implementation?->name);
        $this->assertSame('Testing', $implementation?->title);
        $this->assertSame('https://mcp.test/icon.png', $implementation?->icons[0]->src);
    }

    public function testPreservesRemoteIconSources(): void
    {
        $implementation = Implementation::from([
            'name' => 'Remote Server',
            'version' => '1.0.0',
            'icons' => [['src' => 'icon.png']],
        ]);

        $this->assertSame('icon.png', $implementation?->icons[0]->src);
    }

    #[DataProvider('malformedPayloads')]
    public function testReturnsNullRatherThanCrashingOnAnyMalformedPayload(mixed $payload): void
    {
        $this->assertNull(Implementation::from($payload));
    }

    /**
     * Provide malformed implementation metadata.
     */
    public static function malformedPayloads(): array
    {
        return [
            'not an array' => ['nope'],
            'missing name' => [['version' => '1.0.0']],
            'missing version' => [['name' => 'Test Server']],
            'non string name' => [['name' => 123, 'version' => '1.0.0']],
            'non string title' => [['name' => 'Test Server', 'version' => '1.0.0', 'title' => 123]],
            'non array icons' => [['name' => 'Test Server', 'version' => '1.0.0', 'icons' => 'nope']],
            'non array icon entry' => [['name' => 'Test Server', 'version' => '1.0.0', 'icons' => [1, 2]]],
            'non string icon src' => [['name' => 'Test Server', 'version' => '1.0.0', 'icons' => [['src' => 123]]]],
            'non array icon sizes' => [['name' => 'Test Server', 'version' => '1.0.0', 'icons' => [['src' => 'a', 'sizes' => 'big']]]],
        ];
    }

    public function testPreservesZeroTextInOptionalMetadata(): void
    {
        $implementation = Implementation::from(['name' => 'server', 'version' => '1.0', 'title' => '0', 'description' => '0']);

        $this->assertSame('0', $implementation?->title);
        $this->assertSame('0', $implementation?->description);
    }
}
