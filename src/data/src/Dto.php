<?php

declare(strict_types=1);

namespace Hypervel\Data;

use Hypervel\Data\Concerns\BaseData as BaseDataConcern;
use Hypervel\Data\Concerns\ValidateableData as ValidateableDataConcern;
use Hypervel\Data\Contracts\BaseData as BaseDataContract;
use Hypervel\Data\Contracts\ValidateableData as ValidateableDataContract;

abstract class Dto implements BaseDataContract, ValidateableDataContract
{
    use BaseDataConcern;
    use ValidateableDataConcern;
}
