<?php

declare(strict_types=1);

namespace LegacyModelProperties;

use Hypervel\Database\Eloquent\Attributes\Guarded;
use Hypervel\Database\Eloquent\Attributes\Hidden;
use Hypervel\Database\Eloquent\Attributes\Table;
use Hypervel\Database\Eloquent\Attributes\WithoutIncrementing;
use Hypervel\Database\Eloquent\Model;
use UnitEnum;

enum ConnectionName: string
{
    case Reporting = 'reporting';
}

class Post extends Model
{
    public const string TABLE = 'posts';

    protected ?string $table = self::TABLE;

    protected UnitEnum|string|null $connection = ConnectionName::Reporting;

    protected array $fillable = ['title', 'body'];

    protected array $guarded = [];

    public bool $timestamps = false;

    protected ?string $dateFormat = 'U';

    protected array $touches = ['author'];

    protected ?string $primaryKey = 'id';
}

class Token extends Model
{
    protected ?string $primaryKey = 'uuid';

    protected string $keyType = 'string';

    public bool $incrementing = false;
}

class SequencedToken extends Token
{
    public bool $incrementing = true;
}

#[Table('invoices')]
class Invoice extends Model
{
    protected ?string $primaryKey = 'number';

    protected array $guarded = ['number'];
}

#[WithoutIncrementing]
class Ledger extends Model
{
}

class LedgerEntry extends Ledger
{
    // Ledger's attribute overwrites the property, so it has no effect.
    public bool $incrementing = true;
}

#[Hidden(['password'])]
class Account extends Model
{
}

class AdminAccount extends Account
{
    protected array $hidden = ['token'];
}

abstract class BaseUser extends Model
{
    protected array $hidden = ['password'];

    public bool $timestamps = false;
}

class Guest extends BaseUser
{
    // #[Hidden([])] would merge in the parent's password.
    protected array $hidden = [];

    // #[WithoutTimestamps] has no counterpart enabling timestamps.
    public bool $timestamps = true;
}

class Report extends Model
{
    protected ?string $table = 'reports';

    public function __construct(array $attributes = [])
    {
        $this->table = 'reports_' . date('Y');

        parent::__construct($attributes);
    }
}

class ArchivedReport extends Report
{
    // Report's constructor replaces the table this declares.
    protected ?string $table = 'archived_reports';
}

trait HasIntegerKey
{
    // PHP requires this to match the inherited declaration, so it only restates it.
    protected string $keyType = 'int';
}

class Device extends Model
{
    use HasIntegerKey;
}

class NotAModel
{
    protected ?string $table = 'not_a_model';
}

class KeylessToken extends Token
{
    // Table's key applies only over the default 'id', so no attribute gives this null key.
    protected ?string $primaryKey = null;
}

#[Guarded(['secret'])]
class Note extends Model
{
    // This overrides the class's own #[Guarded], which #[Unguarded] beside it would not.
    protected array $guarded = [];
}

class Draft extends Model
{
    // The constructor's makeVisible() changes this list.
    protected array $hidden = ['body'];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);

        $this->makeVisible('body');
    }
}
