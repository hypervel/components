<?php

declare(strict_types=1);

namespace Hypervel\Tests\Foundation\Testing\Concerns;

use Hypervel\Foundation\Testing\Concerns\InteractsWithViews;
use Hypervel\Testbench\TestCase;
use Hypervel\Testing\TestComponent;
use Hypervel\View\Component;

class InteractsWithViewsTest extends TestCase
{
    use InteractsWithViews;

    public function testBladeCorrectlyRendersString()
    {
        $string = (string) $this->blade('@if(true)test @endif');

        $this->assertSame('test ', $string);
    }

    public function testBladeCleansUpTempFiles()
    {
        // Capture temp files created before blade() to isolate our file
        $before = glob(sys_get_temp_dir() . '/hypervel-blade*.blade.php') ?: [];

        $this->blade('@if(true)test @endif');

        $after = glob(sys_get_temp_dir() . '/hypervel-blade*.blade.php') ?: [];
        $newFiles = array_diff($after, $before);
        $this->assertCount(1, $newFiles, 'blade() should create exactly one temp file');

        $tempFile = reset($newFiles);
        $placeholderFile = str_replace('.blade.php', '', $tempFile);

        // tempnam() placeholder should be cleaned up immediately
        $this->assertFileDoesNotExist($placeholderFile);

        // Blade file should exist until teardown
        $this->assertFileExists($tempFile);

        // Simulate teardown — triggers beforeApplicationDestroyed callbacks
        $this->callBeforeApplicationDestroyedCallbacks();

        $this->assertFileDoesNotExist($tempFile);
    }

    public function testComponentCanAccessPublicProperties()
    {
        $exampleComponent = new class extends Component {
            public string $foo = 'bar';

            public function speak(): string
            {
                return 'hello';
            }

            public function render(): string
            {
                return 'rendered content';
            }
        };

        $component = $this->component(get_class($exampleComponent));

        $this->assertSame('bar', $component->foo);
        $this->assertSame('hello', $component->speak());
        $component->assertSee('content');
    }

    public function testComponentMacroable()
    {
        TestComponent::macro('foo', fn (): string => 'bar');

        $exampleComponent = new class extends Component {
            public function render(): string
            {
                return 'rendered content';
            }
        };

        $component = $this->component(get_class($exampleComponent));

        $this->assertSame('bar', $component->foo());
    }
}
