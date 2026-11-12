<?php

declare(strict_types=1);

namespace NoUnnecessaryCollectionCallHypervel;

use App\User;
use Hypervel\Database\Eloquent\Attributes\CollectedBy;
use Hypervel\Database\Eloquent\Attributes\ObservedBy;
use Hypervel\Database\Eloquent\Attributes\Scope;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Casts\AsStringable;
use Hypervel\Database\Eloquent\Casts\Attribute;
use Hypervel\Database\Eloquent\Collection;
use Hypervel\Database\Eloquent\HasCollection;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Support\Facades\DB;

class Member extends Model
{
    protected ?string $table = 'users';

    protected array $casts = [
        'email' => AsStringable::class,
        'email_verified_at' => 'datetime',
    ];

    /** @return Attribute<string, never> */
    protected function name(): Attribute
    {
        return Attribute::get(fn (string $value): string => strtoupper($value));
    }

    /** @param Builder<self> $query */
    #[Scope]
    protected function whereVerified(Builder $query): void
    {
        $query->whereNotNull('email_verified_at');
    }
}

#[ObservedBy(MemberObserver::class)]
class ObservedMember extends Member
{
}

class MemberObserver
{
    public function retrieved(ObservedMember $member): void
    {
    }
}

class RetrievedListenedMember extends Member
{
}

class RetrievedEventMember extends Member
{
    protected array $dispatchesEvents = ['retrieved' => MemberRetrieved::class];
}

class MemberRetrieved
{
    public function __construct(public RetrievedEventMember $member)
    {
    }
}

class TranslatedMember extends Member
{
    public function getAttributeValue(string $key): mixed
    {
        return parent::getAttributeValue($key);
    }
}

/** @extends Collection<int, CountedMember> */
class CountingCollection extends Collection
{
    public function count(): int
    {
        return parent::count();
    }
}

#[CollectedBy(CountingCollection::class)]
class CountedMember extends Member
{
}

class KeylessMember extends Member
{
    protected ?string $primaryKey = null;
}

/** @extends Collection<int, EmptyMember> */
class EmptyCollection extends Collection
{
    public function isEmpty(): bool
    {
        return true;
    }
}

#[CollectedBy(EmptyCollection::class)]
class EmptyMember extends Member
{
}

class ClassCountedMember extends Member
{
    protected static string $collectionClass = CountingCollection::class;
}

class CollectionTraitMember extends Member
{
    /** @use HasCollection<Collection<int, static>> */
    use HasCollection;
}

class KeyNamedMember extends Member
{
    public function getKeyName(): ?string
    {
        return $this->getRawOriginal('key_column') ?? 'id';
    }
}

class OffsetMember extends Member
{
    public function offsetGet($offset): mixed
    {
        return parent::offsetGet($offset);
    }
}

class MagicMember extends Member
{
    public function __get(string $key): mixed
    {
        return parent::__get($key);
    }
}

/** @param Builder<Member> $query */
function collectionCalls(Member $member, Builder $query, string $column, int $limit, bool $flag): void
{
    Member::all()->count();
    Member::where('id', '>', 1)->get()->count();
    Member::query()->whereIn('id', [1])->orderBy('id')->get()->first();
    Member::query()->forPage(2)->skip(1)->limit(5)->get()->first();
    Member::query()->limit(0)->offset(3)->get()->isEmpty();
    Member::with([])->whereEmail('a')->join('accounts', 'accounts.user_id', '=', 'users.id')->get()->isNotEmpty();
    Member::query()->latest()->get()->take(2);
    Member::query()->get()->pluck('id');
    Member::all()->pluck('email_verified_at');
    Member::query()->limit(3)->get()->pluck('id');
    Member::query()->whereKey(1)->get()->modelKeys();
    Member::pluck('email')->count();
    ($flag ? Member::class : ObservedMember::class)::all()->count();
    CountedMember::all()->first();
    TranslatedMember::all()->isEmpty();
    DB::table('users')->where('id', 1)->get()->first();
    DB::connection('sqlite')->table('users')->orderBy('id')->get()->count();
    DB::table('users')->pluck('email')->isEmpty();
    CollectionTraitMember::all()->count();
    KeyNamedMember::all()->pluck('id');
    OffsetMember::all()->modelKeys();

    Member::query()->limit(0)->get()->first();
    Member::query()->limit($limit)->get()->first();
    Member::query()->forPage(2, 0)->get()->first();
    Member::query()->limit(5)->get()->count();
    Member::query()->skip(1)->get()->take(2);
    Member::query()->get()->take(-1);
    Member::query()->get()->take($limit);
    Member::query()->get()->first(fn (Member $member): bool => true);
    Member::query()->get(['id'])->count();
    Member::all('id')->count();
    Member::query()->select('id')->get()->count();
    Member::query()->distinct()->get()->pluck('email_verified_at');
    Member::query()->when($flag, fn (Builder $query): Builder => $query)->get()->count();
    Member::query()->whereVerified()->get()->count();
    Member::query()->whereGlobalMacro()->get()->count();
    Member::query()->whereQueryMacro()->get()->count();
    Member::query()->get()->pluck('name');
    Member::query()->get()->pluck('email');
    Member::query()->get()->pluck('missing');
    Member::query()->get()->pluck('id', 'email');
    Member::query()->get()->pluck($column);
    Member::query()->join('accounts', 'accounts.user_id', '=', 'users.id')->get()->pluck('id');
    Member::query()->join('accounts', 'accounts.user_id', '=', 'users.id')->get()->modelKeys();
    Member::query()->pluck('id')->first();
    ($flag ? Member::class : CountedMember::class)::all()->count();
    ObservedMember::all()->pluck('id');
    RetrievedListenedMember::all()->pluck('id');
    RetrievedEventMember::all()->modelKeys();
    TranslatedMember::all()->pluck('id');
    CountedMember::all()->count();
    KeylessMember::all()->modelKeys();
    EmptyMember::all()->isNotEmpty();
    ClassCountedMember::all()->count();
    KeyNamedMember::all()->modelKeys();
    OffsetMember::all()->pluck('id');
    MagicMember::all()->modelKeys();
    User::query()->get()->count();
    $member->newQuery()->get()->count();
    $query->get()->count();
    DB::table('users')->get()->pluck('email');
    DB::table($column)->get()->count();
    DB::connection($column)->table('users')->get()->count();
}
