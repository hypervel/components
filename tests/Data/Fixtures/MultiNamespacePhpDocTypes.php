<?php

declare(strict_types=1);

namespace Hypervel\Tests\Data\Fixtures\First {
    use Hypervel\Tests\Data\Fixtures\Types\ImportedType as SharedAlias;

    class MultiNamespaceFirst
    {
    }
}

namespace Hypervel\Tests\Data\Fixtures\Second {
    use Hypervel\Tests\Data\Fixtures\Types\GroupedType as SharedAlias;

    class MultiNamespaceSecond
    {
    }
}
