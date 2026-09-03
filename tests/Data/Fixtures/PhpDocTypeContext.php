<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures;

use Hypervel\Tests\Data\Fixtures\Types\ImportedType;
use Hypervel\Tests\Data\Fixtures\Types\{GroupedType as GroupAlias};

/**
 * @property ImportedType $imported
 * @property GroupAlias $grouped
 */
class PhpDocTypeContext extends TypeNameResolverParent
{
}
