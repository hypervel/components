<?php

declare(strict_types=1);

namespace Hypervel\Database\Query\Grammars;

use Hyperf\Database\Query\Grammars\MySqlGrammar as BaseMySqlGrammar;
use Hypervel\Database\Concerns\CompilesJsonPaths;

class MySqlGrammar extends BaseMySqlGrammar
{
    use CompilesJsonPaths;
}
