<?php

declare(strict_types=1);

namespace Hypervel\Data;

use Hypervel\Contracts\Database\Eloquent\Castable as EloquentCastable;
use Hypervel\Data\Concerns\AppendableData as AppendableDataConcern;
use Hypervel\Data\Concerns\BaseData as BaseDataConcern;
use Hypervel\Data\Concerns\EloquentCastableData as EloquentCastableDataConcern;
use Hypervel\Data\Concerns\EmptyData as EmptyDataConcern;
use Hypervel\Data\Concerns\IncludeableData as IncludeableDataConcern;
use Hypervel\Data\Concerns\ResponsableData as ResponsableDataConcern;
use Hypervel\Data\Concerns\TransformableData as TransformableDataConcern;
use Hypervel\Data\Concerns\ValidateableData as ValidateableDataConcern;
use Hypervel\Data\Concerns\WrappableData as WrappableDataConcern;
use Hypervel\Data\Contracts\AppendableData as AppendableDataContract;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\Contracts\EmptyData as EmptyDataContract;
use Hypervel\Data\Contracts\IncludeableData as IncludeableDataContract;
use Hypervel\Data\Contracts\ResponsableData as ResponsableDataContract;
use Hypervel\Data\Contracts\TransformableData as TransformableDataContract;
use Hypervel\Data\Contracts\ValidateableData as ValidateableDataContract;
use Hypervel\Data\Contracts\WrappableData as WrappableDataContract;

abstract class Data implements AppendableDataContract, BaseDataContract, EloquentCastable, EmptyDataContract, IncludeableDataContract, ResponsableDataContract, TransformableDataContract, ValidateableDataContract, WrappableDataContract
{
    use AppendableDataConcern;
    use BaseDataConcern;
    use EloquentCastableDataConcern;
    use EmptyDataConcern;
    use IncludeableDataConcern;
    use ResponsableDataConcern;
    use TransformableDataConcern;
    use ValidateableDataConcern;
    use WrappableDataConcern;
}
