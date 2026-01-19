<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\SQLite\Query\Grammars\SQLiteGrammar as BaseSQLiteGrammar;
use Hypervel\Database\Concerns\CompilesJsonPaths;

class SQLiteGrammar extends BaseSQLiteGrammar
{
    use CompilesJsonPaths;
}
