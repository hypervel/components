<?php

declare(strict_types=1);

namespace Hypervel\Data;

use Hypervel\Data\Concerns\AppendableData as AppendableDataConcern;
use Hypervel\Data\Concerns\BaseData as BaseDataConcern;
use Hypervel\Data\Concerns\EmptyData as EmptyDataConcern;
use Hypervel\Data\Concerns\IncludeableData as IncludeableDataConcern;
use Hypervel\Data\Concerns\TransformableData as TransformableDataConcern;
use Hypervel\Data\Concerns\WrappableData as WrappableDataConcern;
use Hypervel\Data\Contracts\AppendableData as AppendableDataContract;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\Contracts\EmptyData as EmptyDataContract;
use Hypervel\Data\Contracts\IncludeableData as IncludeableDataContract;
use Hypervel\Data\Contracts\TransformableData as TransformableDataContract;
use Hypervel\Data\Contracts\WrappableData as WrappableDataContract;

abstract class Resource implements AppendableDataContract, BaseDataContract, EmptyDataContract, IncludeableDataContract, TransformableDataContract, WrappableDataContract
{
    use AppendableDataConcern;
    use BaseDataConcern;
    use EmptyDataConcern;
    use IncludeableDataConcern;
    use TransformableDataConcern;
    use WrappableDataConcern;
}
