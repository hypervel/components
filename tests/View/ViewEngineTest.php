<?php

declare(strict_types=1);

namespace Hypervel\Tests\View;

use Hypervel\Tests\TestCase;
use Hypervel\View\Engines\Engine;

class ViewEngineTest extends TestCase
{
    public function testLastRenderedIsNullUntilAViewIsRecorded(): void
    {
        $engine = new class extends Engine {
            public function record(string $path): void
            {
                $this->lastRendered = $path;
            }
        };

        $this->assertNull($engine->getLastRendered());

        $engine->record('/views/welcome.php');

        $this->assertSame('/views/welcome.php', $engine->getLastRendered());
    }
}
