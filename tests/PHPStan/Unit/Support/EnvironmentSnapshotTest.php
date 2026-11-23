<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Unit\Support;

use Hypervel\PHPStan\Support\EnvironmentSnapshot;
use Hypervel\Tests\PHPStan\TestCase;
use ReflectionProperty;

class EnvironmentSnapshotTest extends TestCase
{
    public function testPatternsMatchOnlyApplicationEnvironment(): void
    {
        $captured = new ReflectionProperty(EnvironmentSnapshot::class, 'captured');
        $original = $captured->getValue();
        $digest = static function (array $server, array $process, array $names = []) use ($captured): string {
            $captured->setValue(null, ['server' => $server, 'env' => [], 'process' => $process]);

            return EnvironmentSnapshot::digest($names, $names === [] ? ['*'] : []);
        };

        try {
            $base = $digest(['REQUEST_TIME' => 1, 'APP_KEY' => 'a'], ['APP_KEY' => 'a', 'PHPRC' => '/tmp/phpstan-restart-ini-1']);

            // PHP's request metadata, and the ini file PHPStan's restart writes anew on every run.
            $this->assertSame($base, $digest(['REQUEST_TIME' => 2, 'APP_KEY' => 'a'], ['APP_KEY' => 'a', 'PHPRC' => '/tmp/phpstan-restart-ini-2']));
            $this->assertNotSame($base, $digest(['REQUEST_TIME' => 1, 'APP_KEY' => 'b'], ['APP_KEY' => 'b', 'PHPRC' => '/tmp/phpstan-restart-ini-1']));

            // A named read of PHPRC still depends on it.
            $this->assertNotSame(
                $digest([], ['PHPRC' => '/tmp/phpstan-restart-ini-1'], ['PHPRC']),
                $digest([], ['PHPRC' => '/tmp/phpstan-restart-ini-2'], ['PHPRC']),
            );
        } finally {
            $captured->setValue(null, $original);
        }
    }
}
