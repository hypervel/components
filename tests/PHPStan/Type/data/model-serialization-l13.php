<?php

namespace ModelSerializationLaravel13;

use Hypervel\Database\Eloquent\Attributes\Appends;
use Hypervel\Database\Eloquent\Attributes\Hidden;
use Hypervel\Database\Eloquent\Attributes\Visible;
use Hypervel\Database\Eloquent\Model;

use function PHPStan\Testing\assertType;

#[Appends('label', 'secret')]
#[Hidden(['secret', 'name'])]
#[Visible('id', 'name', 'label', 'secret')]
class AttributeSerializationModel extends Model
{
    protected ?string $table = 'users';

    public function getLabelAttribute(): string
    {
        return 'label';
    }

    public function getSecretAttribute(): string
    {
        return 'secret';
    }
}

class InheritedAttributeSerializationModel extends AttributeSerializationModel
{
}

#[Hidden('label')]
class ReplacedAttributeSerializationModel extends AttributeSerializationModel
{
    protected array $hidden = ['secret'];
}

function serialization(
    AttributeSerializationModel $model,
    InheritedAttributeSerializationModel $inherited,
    ReplacedAttributeSerializationModel $replaced,
): void {
    // Unlike upstream, ids read int: the test connection is SQLite, which does not enforce unsigned.
    assertType('array{id?: int, label?: string, ...<string, mixed>}', $model->attributesToArray());
    assertType('array{id?: int, label?: string, ...<string, mixed>}', $inherited->toArray());
    assertType('array{id?: int, name?: string, ...<string, mixed>}', $replaced->attributesToArray());
}
