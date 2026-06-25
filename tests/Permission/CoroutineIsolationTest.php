<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission;

use function Hypervel\Coroutine\parallel;

class CoroutineIsolationTest extends TestCase
{
    public function testTeamIdIsIsolatedPerCoroutine(): void
    {
        [$first, $second] = parallel([
            function (): int|string|null {
                setPermissionsTeamId(1);
                usleep(5000);

                return getPermissionsTeamId();
            },
            function (): int|string|null {
                setPermissionsTeamId(2);
                usleep(5000);

                return getPermissionsTeamId();
            },
        ]);

        $this->assertSame(1, $first);
        $this->assertSame(2, $second);
        $this->assertNull(getPermissionsTeamId());
    }
}
