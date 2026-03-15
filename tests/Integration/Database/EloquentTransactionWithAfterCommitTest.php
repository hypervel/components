<?php

declare(strict_types=1);

namespace Hypervel\Tests\Integration\Database;

/**
 * @internal
 * @coversNothing
 */
class EloquentTransactionWithAfterCommitTest extends DatabaseTestCase
{
    use EloquentTransactionWithAfterCommitTests;

    protected function afterRefreshingDatabase(): void
    {
        $this->createTransactionTestTables();
    }
}
