<?php

declare(strict_types=1);

namespace Hypervel\Tests\Permission\Fixtures\Models;

use Hypervel\Permission\WildcardPermission as BaseWildcardPermission;

class WildcardPermission extends BaseWildcardPermission
{
    public const string WILDCARD_TOKEN = '@';

    /** @var non-empty-string */
    public const string PART_DELIMITER = ':';

    /** @var non-empty-string */
    public const string SUBPART_DELIMITER = ';';
}
