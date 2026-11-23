<?php

declare(strict_types=1);

namespace NoModelMakeHypervel;

use App\PostBuilder;
use Attribute;
use App\User;
use Hypervel\Database\Eloquent\Attributes\Boot;
use Hypervel\Database\Eloquent\Attributes\Connection;
use Hypervel\Database\Eloquent\Attributes\Initialize;
use Hypervel\Database\Eloquent\Attributes\ScopedBy;
use Hypervel\Database\Eloquent\Attributes\UseEloquentBuilder;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Eloquent\Scope;
use Hypervel\Tests\PHPStan\Fixtures\Rules\ScopedAtBootModel;
use UnitEnum;

abstract class FixedConnectionModel extends Model
{
    protected UnitEnum|string|null $connection = 'sqlite';
}

class PlainModel extends FixedConnectionModel
{
}

class OwnMake extends FixedConnectionModel
{
    public static function make(): static
    {
        return new static;
    }
}

class Booted extends FixedConnectionModel
{
    protected static function booted(): void
    {
        static::addGlobalScope('pending', static fn (Builder $builder) => $builder->withAttributes(['status' => 'pending']));
    }
}

trait BootsScope
{
    public static function bootBootsScope(): void
    {
    }
}

class TraitBooted extends FixedConnectionModel
{
    use BootsScope;
}

trait InitializesState
{
    public function initializeInitializesState(): void
    {
    }
}

class TraitInitialized extends FixedConnectionModel
{
    use InitializesState;
}

class AttributedBoot extends FixedConnectionModel
{
    #[Boot]
    protected static function registerScopes(): void
    {
    }
}

class AttributedInitialize extends FixedConnectionModel
{
    #[Initialize]
    protected function prepareState(): void
    {
    }
}

#[UseEloquentBuilder(PostBuilder::class)]
class CustomBuilder extends FixedConnectionModel
{
}

class PendingScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
    }

    public function extend(Builder $builder): void
    {
        $builder->withAttributes(['status' => 'pending']);
    }
}

#[ScopedBy(PendingScope::class)]
class AttributedScope extends FixedConnectionModel
{
}

#[Attribute(Attribute::TARGET_CLASS)]
class ScopedByPending extends ScopedBy
{
    public function __construct()
    {
        parent::__construct(PendingScope::class);
    }
}

#[ScopedByPending]
class SubclassedAttributeScope extends FixedConnectionModel
{
}

class BootListenedModel extends FixedConnectionModel
{
}

#[Connection('sqlite')]
class AttributeConnection extends Model
{
}

class DefaultConnection extends Model
{
}

class ReadConnection extends Model
{
    protected UnitEnum|string|null $connection = 'sqlite::read';
}

class ResolvedConnection extends FixedConnectionModel
{
    public function getConnectionName(): ?string
    {
        return 'sqlite';
    }
}

class OpenModel extends FixedConnectionModel
{
    public static function build(): static
    {
        return static::make();
    }
}

final class FinalModel extends FixedConnectionModel
{
}

/**
 * @param class-string<PlainModel> $openClass
 * @param class-string<FinalModel> $finalClass
 */
function instantiate(PlainModel $openModel, FinalModel $finalModel, string $openClass, string $finalClass, bool $flag): void
{
    PlainModel::make();
    PlainModel::MAKE();
    AttributeConnection::make();
    $finalModel::make();
    $finalClass::make();
    ($flag ? PlainModel::class : FinalModel::class)::make();
    ($flag ? PlainModel::class : Booted::class)::make();
    $openModel::make();
    $openClass::make();
    DefaultConnection::make();
    ReadConnection::make();
    ResolvedConnection::make();
    OwnMake::make();
    Booted::make();
    TraitBooted::make();
    TraitInitialized::make();
    AttributedBoot::make();
    AttributedInitialize::make();
    CustomBuilder::make();
    AttributedScope::make();
    SubclassedAttributeScope::make();
    ScopedAtBootModel::make();
    BootListenedModel::make();
    User::make();
    Model::make();
}
