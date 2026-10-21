<?php

declare(strict_types=1);

namespace Hypervel\Tests\Testing\PHPUnit\Fixtures;

use GuzzleHttp\Promise\Utils;
use Hypervel\Tests\TestCase;
use WeakReference;

class GuzzleCleanupFixture extends TestCase
{
    protected bool $runTestsInCoroutine = false;

    protected static ?WeakReference $reference = null;

    public function testLeaveAnOutsideCallbackPending(): void
    {
        $payload = (object) ['message' => 'abandoned-callback-ran'];
        self::$reference = WeakReference::create($payload);
        Utils::queue()->add(static function () use ($payload): void {
            echo $payload->message;
        });

        $this->assertFalse(Utils::queue()->isEmpty());
    }

    public function testPreviousCallbacksHaveBeenReleasedBeforeTheNextTest(): void
    {
        $this->assertNull(self::$reference->get());
        $this->assertTrue(Utils::queue()->isEmpty());
        Utils::queue()->add(static function (): void {
            echo 'abandoned-callback-ran';
        });
    }
}
