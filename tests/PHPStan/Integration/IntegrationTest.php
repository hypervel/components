<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Integration;

use PHPStan\Analyser\Analyser;
use PHPStan\Analyser\Error;
use PHPStan\File\FileHelper;
use PHPStan\Testing\PHPStanTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

use function array_map;
use function ksort;
use function sort;
use function sprintf;

class IntegrationTest extends PHPStanTestCase
{
    /**
     * @param null|array<int, array<int, string>> $expectedErrors
     *
     * @throws Throwable
     */
    #[DataProvider('dataIntegrationTests')]
    public function testIntegration(string $file, ?array $expectedErrors = null): void
    {
        $errors = $this->runAnalyse($file);

        if ($expectedErrors === null) {
            $this->assertNoErrors($errors);
        } else {
            $this->assertSameErrorMessages($file, $expectedErrors, $errors);
        }
    }

    /** @return iterable<array{0: string, 1?: array<int, array<int, string>>}> */
    public static function dataIntegrationTests(): iterable
    {
        self::getContainer();

        yield [__DIR__ . '/data/bug-2074.php'];

        // Hypervel: the request body parameters are natively typed as Laravel documents them, so null and other
        // objects throw a TypeError instead of reaching Guzzle.
        yield [
            __DIR__ . '/data/bug-2426.php',
            [
                9 => ['Parameter #2 $data of static method Hypervel\Http\Client\PendingRequest<false>::post() expects array|Hypervel\Contracts\Support\Arrayable|JsonSerializable, null given.'],
                10 => ['Parameter #2 $data of static method Hypervel\Http\Client\PendingRequest<false>::post() expects array|Hypervel\Contracts\Support\Arrayable|JsonSerializable, object{}&stdClass given.'],
                11 => ['Parameter #2 $data of static method Hypervel\Http\Client\PendingRequest<false>::patch() expects array|Hypervel\Contracts\Support\Arrayable|JsonSerializable, null given.'],
                12 => ['Parameter #2 $data of static method Hypervel\Http\Client\PendingRequest<false>::put() expects array|Hypervel\Contracts\Support\Arrayable|JsonSerializable, null given.'],
                13 => ['Parameter #2 $data of static method Hypervel\Http\Client\PendingRequest<false>::delete() expects array|Hypervel\Contracts\Support\Arrayable|JsonSerializable, null given.'],
            ],
        ];

        yield [__DIR__ . '/data/bug-2431.php'];

        // Hypervel: User's public email() accessor, whose Attribute has no generic types, reads as mixed, because
        // Eloquent calls it. Upstream ignores it.
        yield 'bug-2516' => [
            __DIR__ . '/data/bug-2516.php',
            [
                14 => ['Method Bug2516\Stations::all() should return Hypervel\Support\Collection<int, array{id: int, name: string, email: string}> but returns Hypervel\Support\Collection<int, array{id: int, name: string, email: mixed}>.'],
            ],
        ];

        // Hypervel: a model that cannot be instantiated has no known schema, so its properties are not reported missing.
        yield 'bug-2545' => [
            __DIR__ . '/data/bug-2545.php',
            [
                7 => ['Class Bug2545\CalendarEvent implements unknown interface Bug2545\MissingInterface.'],
            ],
        ];

        yield 'bug-2582' => [__DIR__ . '/data/bug-2582.php'];

        // Hypervel: a date attribute accepts what HasAttributes::fromDateTime() converts.
        yield 'bug-1744' => [
            __DIR__ . '/data/bug-1744.php',
            [
                13 => ['Property App\ModelWithDateColumnCasts::$created_at (DateTimeInterface|float|int|string|null) does not accept array<string, string|null>.'],
            ],
        ];

        yield [__DIR__ . '/data/bug-final_model_query.php'];
        yield [__DIR__ . '/data/test-case-extension.php'];
        yield [__DIR__ . '/data/model-builder.php'];

        yield 'eloquent-builder-paginate' => [
            __DIR__ . '/data/eloquent-builder-paginate.php',
            [
                34 => ['Parameter #1 $perPage of method Hypervel\Database\Eloquent\Builder<App\User>::paginate() expects (Closure(int): (int|null))|int|null, Closure(string): 15 given.'],
                35 => ['Parameter #1 $perPage of method Hypervel\Database\Eloquent\Builder<App\User>::paginate() expects (Closure(int): (int|null))|int|null, Closure(int): \'15\' given.'],
            ],
        ];

        yield 'relation-cursor-paginate' => [__DIR__ . '/data/relation-cursor-paginate.php'];

        // Hypervel: Model::newEloquentBuilder() types $query natively, so an untyped override has no PHPDoc type to inherit.
        yield [
            __DIR__ . '/data/custom-builder-return-this.php',
            [
                14 => ['Method CustomBuilderReturnThis\Post::newEloquentBuilder() has parameter $query with no type specified.'],
            ],
        ];

        yield 'framework-docblock-types' => [__DIR__ . '/data/framework-docblock-types.php'];

        // Hypervel: eager-loading constraints accept any callable, as Builder::combineConstraints() calls them, and
        // each key's callback is checked against that key's own relation instead of a union of the keys' relations.
        yield 'model-with-relations' => [
            __DIR__ . '/data/model-with-relations.php',
            [
                22 => ['Parameter #1 $relations of static method Hypervel\Database\Eloquent\Model::with() expects array{accounts: array|(callable(Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string, group: array|(callable(Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>): mixed)|string}|string, array{accounts: Closure(Hypervel\Database\Eloquent\Relations\BelongsTo): void, group: Closure(Hypervel\Database\Eloquent\Relations\HasMany): void} given.'],
                27 => ['Parameter #1 $relations of static method Hypervel\Database\Eloquent\Model::with() expects array<array|(callable(Hypervel\Database\Eloquent\Relations\Relation<*, *, *>): mixed)|string>|string, non-empty-array<string, Closure(Hypervel\Database\Eloquent\Relations\HasMany): void> given.'],
                28 => ['Parameter #1 $relations of static method Hypervel\Database\Eloquent\Model::with() expects array{accounts: array|(callable(Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string}|string, array{accounts: Closure(Hypervel\Database\Eloquent\Relations\BelongsTo): void} given.'],
                29 => ['Parameter #1 $relations of static method Hypervel\Database\Eloquent\Model::with() expects array{accounts: array|(callable(Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string}|string, array{accounts: Closure(int): void} given.'],
                30 => ['Relation \'missing\' is not found in App\User model.'],
                31 => ['Parameter #1 $relations of static method Hypervel\Database\Eloquent\Model::with() expects array<array|(callable(Hypervel\Database\Eloquent\Relations\Relation<*, *, *>): mixed)|string>|string, non-empty-array<string, Closure(Hypervel\Database\Eloquent\Relations\HasMany): void> given.'],
                34 => ['Parameter #1 $relation of function ModelWithRelationsIntegration\acceptsAccounts expects Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>, Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User> given.'],
            ],
        ];

        // Hypervel: each key's callback is checked against that key's own relation, and eager-loading constraints
        // accept any callable.
        yield 'eager-loading-callbacks' => [
            __DIR__ . '/data/eager-loading-callbacks.php',
            [
                32 => ['Parameter #1 $relations of method Hypervel\Database\Eloquent\Builder<App\User>::with() expects array{accounts: array|(callable(Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string}|string, array{accounts: Closure(Hypervel\Database\Eloquent\Relations\BelongsTo): void} given.'],
                33 => ['Parameter #1 $relations of method Hypervel\Database\Eloquent\Builder<App\User>::withOnly() expects array{accounts: array|(callable(Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string}|string, array{accounts: Closure(int): void} given.'],
                34 => ['Parameter #1 $relations of method Hypervel\Database\Eloquent\Model::load() expects array{accounts: array|(callable(Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string}|string, array{accounts: Closure(Hypervel\Database\Eloquent\Relations\BelongsTo): void} given.'],
                35 => ['Parameter #1 $relations of method Hypervel\Database\Eloquent\Model::loadMissing() expects array{group: array|(callable(Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>): mixed)|string}|string, array{group: Closure(Hypervel\Database\Eloquent\Relations\HasMany): void} given.'],
                36 => ['Parameter #1 $relations of method Hypervel\Database\Eloquent\Collection<int,App\User>::load() expects array{accounts: array|(callable(Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string}|string, array{accounts: Closure(Hypervel\Database\Eloquent\Relations\BelongsTo): void} given.'],
                37 => ['Parameter #1 $relations of method Hypervel\Database\Eloquent\Collection<int,App\User>::loadMissing() expects array{group: array|(callable(Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>): mixed)|string}|string, array{group: Closure(Hypervel\Database\Eloquent\Relations\HasMany): void} given.'],
                38 => ['Relation \'missing\' is not found in App\User model.'],
                39 => ['Parameter #2 $callback of method Hypervel\Database\Eloquent\Builder<App\User>::with() expects (Closure(Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string|null, Closure(Hypervel\Database\Eloquent\Relations\BelongsTo): void given.'],
            ],
        ];

        // Hypervel: per-key aggregate and morph map callbacks, and callbacks that may receive either of two relations.
        yield 'eager-load-maps' => [
            __DIR__ . '/data/eager-load-maps.php',
            [
                36 => ['Parameter #1 $relations of method Hypervel\Database\Eloquent\Collection<int,App\User>::loadSum() expects array{\'accounts as total\': array{}|(callable(Hypervel\Database\Eloquent\Builder<App\Account>): mixed)|string}|string, array{\'accounts as total\': Closure(App\PostBuilder): (App\PostBuilder&Hypervel\Database\Eloquent\Builder<App\Account>)} given.'],
                37 => ['Parameter #2 $relations of method Hypervel\Database\Eloquent\Collection<int,App\Comment>::loadMorph() expects array{post: array{users: array|(callable(Hypervel\Database\Eloquent\Relations\BelongsToMany<App\User, App\Post, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>): mixed)|string}|string}, array{post: array{users: Closure(Hypervel\Database\Eloquent\Relations\HasMany): Hypervel\Database\Eloquent\Relations\HasMany}} given.'],
                38 => ['Parameter #1 $with of method Hypervel\Database\Eloquent\Relations\MorphTo<Hypervel\Database\Eloquent\Model,App\Comment>::morphWith() expects array{post: array{users: array|(callable(Hypervel\Database\Eloquent\Relations\BelongsToMany<App\User, App\Post, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>): mixed)|string}|string}, array{post: array{users: Closure(Hypervel\Database\Eloquent\Relations\HasMany): Hypervel\Database\Eloquent\Relations\HasMany}} given.'],
                39 => ['Parameter #1 $callbacks of method Hypervel\Database\Eloquent\Relations\MorphTo<Hypervel\Database\Eloquent\Model,App\Comment>::constrain() expects array{App\User: callable(Hypervel\Database\Eloquent\Builder<App\User>): mixed}, array{App\User: Closure(App\PostBuilder): (App\PostBuilder&Hypervel\Database\Eloquent\Builder<App\User>)} given.'],
                40 => ['Parameter #2 $relations of method Hypervel\Database\Eloquent\Collection<int,App\Comment>::loadMorph() expects array{App\Group: array{users: array|(callable(Hypervel\Database\Eloquent\Relations\BelongsToMany<App\User, App\Role, Hypervel\Database\Eloquent\Relations\Pivot, \'pivot\'>|Hypervel\Database\Eloquent\Relations\HasMany<App\User, App\Group>): mixed)|string}|string}, array{App\Group: array{users: Closure(Hypervel\Database\Eloquent\Relations\HasMany): Hypervel\Database\Eloquent\Relations\HasMany<App\User, App\Group>}} given.'],
                41 => ['Parameter #2 $callback of method Hypervel\Database\Eloquent\Builder<App\User>::with() expects (Closure(Hypervel\Database\Eloquent\Relations\BelongsTo<App\Group, App\User>|Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string|null, Closure(Hypervel\Database\Eloquent\Relations\HasMany): Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User> given.'],
            ],
        ];

        // Hypervel: a where column may also be a builder or relation subquery, as the query builder runs it.
        yield 'eloquent-where' => [
            __DIR__ . '/data/eloquent-where.php',
            [
                44 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::where() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\User>): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, Closure(Hypervel\Database\Query\Builder): Hypervel\Database\Query\Builder given.'],
                45 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::firstWhere() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\User>): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, Closure(Hypervel\Database\Query\Builder): Hypervel\Database\Query\Builder given.'],
                46 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::whereNot() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\User>): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, Closure(Hypervel\Database\Query\Builder): Hypervel\Database\Query\Builder given.'],
                47 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::orWhereNot() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\User>): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, Closure(Hypervel\Database\Query\Builder): Hypervel\Database\Query\Builder given.'],
                49 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::where() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, static-Closure(Hypervel\Database\Eloquent\Builder): Hypervel\Database\Eloquent\Builder given.'],
                50 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::firstWhere() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, static-Closure(Hypervel\Database\Eloquent\Builder): Hypervel\Database\Eloquent\Builder given.'],
                51 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::whereNot() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, static-Closure(Hypervel\Database\Eloquent\Builder): Hypervel\Database\Eloquent\Builder given.'],
                52 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::orWhereNot() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, static-Closure(Hypervel\Database\Eloquent\Builder): Hypervel\Database\Eloquent\Builder given.'],
                53 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::orWhere() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, Closure(int): void given.'],
            ],
        ];

        // Hypervel: forwarded calls name the model.
        yield [
            __DIR__ . '/data/relationship-query-callbacks.php',
            [
                69 => ['Parameter #3 $callback of static method App\Comment::whereHasMorph() expects (Closure(App\PostBuilder<App\Post>, string): mixed)|null, Closure(int): void given.'],
                70 => ['Parameter #3 $callback of static method App\Comment::whereHasMorph() expects (Closure(App\PostBuilder<App\Post>, string): mixed)|null, Closure(Hypervel\Database\Eloquent\Builder, int): void given.'],
                71 => ['Parameter #3 $callback of static method App\Comment::whereHasMorph() expects (Closure(Hypervel\Database\Eloquent\Builder<App\User>, string): mixed)|null, Closure(App\PostBuilder): void given.'],
                91 => ['Parameter #2 $callback of static method App\User::withWhereHas() expects (Closure(Hypervel\Database\Eloquent\Builder<App\Account>|Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|null, Closure(Hypervel\Database\Eloquent\Builder): void given.'],
                92 => ['Parameter #2 $callback of method Hypervel\Database\Eloquent\Builder<App\User>::withWhereHas() expects (Closure(Hypervel\Database\Eloquent\Builder<App\Account>|Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|null, Closure(Hypervel\Database\Eloquent\Relations\Relation): void given.'],
                126 => ['Parameter #2 $column of static method App\User::whereRelation() expects array<mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\Account>): mixed)|Hypervel\Contracts\Database\Query\Expression|string, Closure(App\PostBuilder): void given.'],
                127 => ['Parameter #3 $column of static method App\Comment::whereMorphRelation() expects array<mixed>|(Closure(App\PostBuilder<App\Post>): mixed)|Hypervel\Contracts\Database\Query\Expression|string, Closure(int): void given.'],
                128 => ['Parameter #3 $column of static method App\Comment::whereMorphRelation() expects array<mixed>|(Closure(App\PostBuilder<App\Post>): mixed)|Hypervel\Contracts\Database\Query\Expression|string, Closure(App\PostBuilder, string): void given.'],
                129 => ['Parameter #2 $column of static method App\User::withWhereRelation() expects array<mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\Account>|Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|Hypervel\Contracts\Database\Query\Expression|string, Closure(Hypervel\Database\Eloquent\Builder): void given.'],
                130 => ['Parameter #2 $callback of method Hypervel\Database\Eloquent\Builder<App\User>::with() expects (Closure(Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>): mixed)|string|null, Closure(Hypervel\Database\Eloquent\Builder): void given.'],
                142 => ['Parameter #3 $callback of static method App\Comment::whereHasMorph() expects (Closure(App\PostBuilder<App\Post>, string): mixed)|null, Closure(Hypervel\Database\Eloquent\Builder<App\User>): void given.'],
            ],
        ];

        // Hypervel: User's public email() accessor reads as mixed; date attributes read as CarbonInterface; SQLite
        // has no unsigned columns; AsEnumCollection also stores the enum's backing values.
        yield [
            __DIR__ . '/data/model-properties.php',
            [
                71 => ['Expected type string, actual: mixed'],
                74 => ['Function testDateCast() should return Carbon\Carbon|null but returns Carbon\CarbonInterface|null.'],
                100 => ['Property App\User::$decimal (float|int|numeric-string) does not accept \'foo\'.'],
                101 => ['Property App\User::$decimal (float|int|numeric-string) does not accept array{}.'],
                110 => ['Property App\User::$nullable_decimal (float|int|numeric-string|null) does not accept \'foo\'.'],
                111 => ['Property App\User::$nullable_decimal (float|int|numeric-string|null) does not accept array{}.'],
                118 => ['Property App\User::$enum_collection (iterable<App\Casts\BackedEnumeration|int|string>|null) does not accept array<int, App\Casts\BasicEnumeration>.'],
            ],
        ];

        // Hypervel: enum casts accept the values Eloquent resolves to a case, a caster's set() decides whether
        // it accepts null, and an attribute without a known column may be set to null.
        yield 'model-cast-writes' => [
            __DIR__ . '/data/model-cast-writes.php',
            [
                18 => ['Property ModelCastWrites\EnumCastModel::$int (int|ModelCastWrites\IntStatus|numeric-string) does not accept \'one\'.'],
                24 => ['Property ModelCastWrites\EnumCastModel::$integer (ModelCastWrites\PureStatus|string) does not accept int.'],
                31 => ['Property ModelCastWrites\CasterModel::$nullable_collection (ModelCastWrites\Label) does not accept null.'],
                45 => ['Property ModelCastWrites\SoftDeleteCastModel::$deleted_at (ModelCastWrites\Label) does not accept DateTimeImmutable.'],
            ],
        ];

        // Hypervel: also covers the has* sequence and trashed() value forms, trashed() on a model that does not
        // soft delete, and a has* name whose method returns no relation.
        yield [
            __DIR__ . '/data/model-factories.php',
            [
                16 => ['Call to an undefined method Database\Factories\Post\PostFactory::trashed().'],
                17 => ['Call to an undefined method Database\Factories\UserFactory::hasGetTable().'],
            ],
        ];

        yield [__DIR__ . '/data/blade-view.php'];

        yield [
            __DIR__ . '/data/view-string-content.php',
            [
                10 => ['Parameter $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                11 => ['Parameter $html of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                12 => ['Parameter $text of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                13 => ['Parameter $markdown of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                14 => [
                    'Parameter #1 $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.',
                    'Parameter #2 $html of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.',
                    'Parameter #3 $text of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.',
                    'Parameter #4 $markdown of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.',
                ],
                15 => ['Parameter $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                28 => [
                    'Parameter $html of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string|null given.',
                    'Parameter $markdown of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string|null given.',
                    'Parameter $text of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string|null given.',
                    'Parameter $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string|null given.',
                ],
                29 => ['Parameter $view of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
                30 => ['Parameter $text of class Hypervel\Mail\Mailables\Content constructor expects view-string|null, string given.'],
            ],
        ];

        yield [
            __DIR__ . '/data/view-string-signatures.php',
            [
                17 => ['Parameter #1 $view of method Hypervel\Contracts\View\Factory::make() expects view-string, string given.'],
                23 => ['Parameter #2 $view of method Hypervel\Routing\Router::view() expects view-string, string given.'],
                29 => ['Parameter #1 $view of method Hypervel\Notifications\Messages\MailMessage::view() expects array<view-string>|view-string, string given.'],
                31 => ['Parameter #1 $view of method Hypervel\Notifications\Messages\MailMessage::markdown() expects view-string, string given.'],
                38 => ['Parameter #1 $value of method Hypervel\Testing\TestResponse<Hypervel\Http\Response>::assertViewIs() expects view-string, string given.'],
                52 => ['Parameter #1 $view of method ViewStringSignatures\UsesInteractsWithViews::view() expects view-string, string given.'],
                59 => ['Parameter #1 $view of static method Hypervel\View\Factory::make() expects view-string, string given.'],
                65 => ['Parameter #2 $view of static method Hypervel\Routing\Router::view() expects view-string, string given.'],
                71 => ['Parameter #1 $textView of method Hypervel\Notifications\Messages\MailMessage::text() expects view-string, string given.'],
                77 => ['Parameter #1 $textView of method Hypervel\Mail\Mailable::text() expects view-string, string given.'],
            ],
        ];

        // Hypervel: retry() passes its callbacks any Throwable, as Laravel's does.
        yield [
            __DIR__ . '/data/helpers.php',
            [
                14 => ['Parameter #3 $sleepMilliseconds of function retry expects Carbon\CarbonInterval|(Closure(int, Throwable): (Carbon\CarbonInterval|float|int))|float|int, Closure(int, Exception): 0 given.'],
                16 => ['Parameter #4 $when of function retry expects (callable(Throwable): bool)|null, Closure(Exception): true given.'],
            ],
        ];
        yield [__DIR__ . '/data/facades.php'];

        // Hypervel: in the model-property entries, calls forwarded through a model or relation name it as the
        // declaring class. Here, a where column may also be a subquery, a key that is not a literal may name a
        // property, and get() takes the array or string Laravel documents.
        yield [
            __DIR__ . '/data/model-property-builder.php',
            [
                15 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::firstWhere() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, \'foo\' given.'],
                16 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::firstWhere() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, \'id\'|\'unionNotExisting\' given.'],
                17 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::where() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, \'foo\' given.'],
                19 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::where() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, \'foo\' given.'],
                20 => ['Parameter #1 $column of method App\User::where() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, \'foo\' given.'],
                25 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::orWhere() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, \'foo\' given.'],
                26 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::orWhere() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, \'foo\' given.'],
                27 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::orWhere() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Eloquent\Builder<App\User>|Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, array{foo: \'foo\'} given.'],
                30 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::value() expects Hypervel\Contracts\Database\Query\Expression|model property of App\User, string given.'],
                35 => ['Parameter #1 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::first() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|model property of App\User, array{\'foo\', \'bar\'} given.'],
                36 => ['Parameter #1 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::first() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|model property of App\User, \'foo\' given.'],
                39 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::where() expects array<int|model property of App\User, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\User, \'roles.foo\' given.'],
                41 => ['Parameter #1 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::get() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|model property of App\User, Hypervel\Contracts\Database\Query\Expression given.'],
                44 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Builder<App\User>::createQuietly() expects array<model property of App\User, mixed>, array<string, string> given.'],
                50 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\Account>::where() expects array<int|model property of App\Account, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\Account, \'foo\' given.'],
                54 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\Account>::where() expects array<int|model property of App\Account, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\Account, \'foo\' given.'],
            ],
        ];

        // Hypervel: findSole() and findOr() take the column array or string Laravel documents.
        yield 'find-sole-find-or' => [
            __DIR__ . '/data/model-property-find-sole-find-or.php',
            [
                6 => ['Parameter #2 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::findSole() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|model property of App\User, array{\'foo\'} given.'],
                7 => ['Parameter #2 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::findSole() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|model property of App\User, \'foo\' given.'],
                10 => ['Parameter #2 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::findSole() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|model property of App\User, Hypervel\Contracts\Database\Query\Expression given.'],
                11 => ['Parameter #2 $columns of static method App\User::findSole() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|model property of App\User, array{\'foo\'} given.'],
                12 => ['Parameter #2 $columns of method Hypervel\Database\Eloquent\Relations\HasMany<App\Account,App\User>::findSole() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\Account>|model property of App\Account, array{\'foo\'} given.'],
                14 => ['Parameter #2 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::findOr() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|(Closure(): null)|model property of App\User, array{\'foo\'} given.'],
                15 => ['Parameter #2 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::findOr() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|(Closure(): null)|model property of App\User, \'foo\' given.'],
                20 => ['Parameter #2 $columns of static method App\User::findOr() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\User>|(Closure(): null)|model property of App\User, array{\'foo\'} given.'],
                21 => ['Parameter #2 $columns of method Hypervel\Database\Eloquent\Relations\HasMany<App\Account,App\User>::findOr() expects \'*\'|array<int, \'*\'|Hypervel\Contracts\Database\Query\Expression|model property of App\Account>|(Closure(): null)|model property of App\Account, array{\'foo\'} given.'],
            ],
        ];

        yield 'force-create-quietly' => [
            __DIR__ . '/data/model-property-force-create-quietly.php',
            [
                5 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Builder<App\User>::forceCreateQuietly() expects array<model property of App\User, mixed>, array<string, string> given.'],
                9 => ['Parameter #1 $attributes of static method App\User::forceCreateQuietly() expects array<model property of App\User, mixed>, array<string, string> given.'],
            ],
        ];

        yield 'increment-or-create' => [
            __DIR__ . '/data/model-property-increment-or-create.php',
            [
                5 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Builder<App\User>::incrementOrCreate() expects array<model property of App\User, mixed>, array<string, string> given.'],
                7 => ['Parameter #2 $column of method Hypervel\Database\Eloquent\Builder<App\User>::incrementOrCreate() expects model property of App\User, string given.'],
                9 => ['Parameter #5 $extra of method Hypervel\Database\Eloquent\Builder<App\User>::incrementOrCreate() expects array<model property of App\User, mixed>, array<string, string> given.'],
                12 => ['Parameter #1 $attributes of static method App\User::incrementOrCreate() expects array<model property of App\User, mixed>, array<string, string> given.'],
            ],
        ];

        // REMOVED: model-property-increment-or-create-relation.php; it asserts Laravel releases before 13.35.

        // Hypervel: a single row may also bind resources.
        yield 'upsert' => [
            __DIR__ . '/data/model-property-upsert.php',
            [
                6 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Builder<App\User>::upsert() expects array<int|model property of App\User, array<model property of App\User, mixed>|bool|float|int|object|resource|string|null>, array<string, string> given.'],
                7 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Builder<App\User>::upsert() expects array<int|model property of App\User, array<model property of App\User, mixed>|bool|float|int|object|resource|string|null>, array<int, array<string, string>> given.'],
                8 => ['Parameter #2 $uniqueBy of method Hypervel\Database\Eloquent\Builder<App\User>::upsert() expects array<int, model property of App\User>|model property of App\User, string given.'],
                9 => ['Parameter #2 $uniqueBy of method Hypervel\Database\Eloquent\Builder<App\User>::upsert() expects array<int, model property of App\User>|model property of App\User, array<int, string> given.'],
                10 => ['Parameter #3 $update of method Hypervel\Database\Eloquent\Builder<App\User>::upsert() expects array<int|model property of App\User, mixed>|null, array<string, Hypervel\Contracts\Database\Query\Expression> given.'],
                18 => ['Parameter #1 $values of static method App\User::upsert() expects array<int|model property of App\User, array<model property of App\User, mixed>|bool|float|int|object|resource|string|null>, array<string, string> given.'],
                22 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::upsert() expects array<int|model property of App\Account, array<model property of App\Account, mixed>|bool|float|int|object|resource|string|null>, array<string, string> given.'],
                23 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::upsert() expects array<int|model property of App\Account, array<model property of App\Account, mixed>|bool|float|int|object|resource|string|null>, array<int, array<string, string>> given.'],
                24 => ['Parameter #2 $uniqueBy of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::upsert() expects array<int, model property of App\Account>|model property of App\Account, string given.'],
                36 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Builder<App\User>::upsert() expects array<int|model property of App\User, array<model property of App\User, mixed>|bool|float|int|object|resource|string|null>, array<int, array<string, string>> given.'],
            ],
        ];

        yield 'with-attributes' => [
            __DIR__ . '/data/model-property-with-attributes.php',
            [
                6 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Builder<App\User>::withAttributes() expects array<model property of App\User, mixed>|Hypervel\Contracts\Database\Query\Expression|model property of App\User, array<string, string> given.'],
                7 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Builder<App\User>::withAttributes() expects array<model property of App\User, mixed>|Hypervel\Contracts\Database\Query\Expression|model property of App\User, string given.'],
                14 => ['Parameter #1 $attributes of static method App\User::withAttributes() expects array<model property of App\User, mixed>|Hypervel\Contracts\Database\Query\Expression|model property of App\User, array<string, string> given.'],
                18 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasMany<App\Account,App\User>::withAttributes() expects array<model property of App\Account, mixed>|Hypervel\Contracts\Database\Query\Expression|model property of App\Account, array<string, string> given.'],
                19 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasMany<App\Account,App\User>::withAttributes() expects array<model property of App\Account, mixed>|Hypervel\Contracts\Database\Query\Expression|model property of App\Account, string given.'],
            ],
        ];

        yield 'with-attributes-as-conditions' => [
            __DIR__ . '/data/model-property-with-attributes-l12-4.php',
            [
                5 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Builder<App\User>::withAttributes() expects array<model property of App\User, mixed>|Hypervel\Contracts\Database\Query\Expression|model property of App\User, array<string, string> given.'],
                6 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Builder<App\User>::withAttributes() expects array<model property of App\User, mixed>|Hypervel\Contracts\Database\Query\Expression|model property of App\User, string given.'],
            ],
        ];

        yield 'fill-and-insert' => [
            __DIR__ . '/data/model-property-fill-and-insert-l12-6.php',
            [
                5 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Builder<App\User>::fillAndInsert() expects array<int, array<model property of App\User, mixed>>, array<int, array<string, string>> given.'],
                6 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Builder<App\User>::fillAndInsertOrIgnore() expects array<int, array<model property of App\User, mixed>>, array<int, array<string, string>> given.'],
                7 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Builder<App\User>::fillAndInsertGetId() expects array<model property of App\User, mixed>, array<string, string> given.'],
                8 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Builder<App\User>::fillForInsert() expects array<int, array<model property of App\User, mixed>>, array<int, array<string, string>> given.'],
                16 => ['Parameter #1 $values of static method App\User::fillAndInsert() expects array<int, array<model property of App\User, mixed>>, array<int, array<string, string>> given.'],
                29 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Builder<App\User>::fillAndInsert() expects array<int, array<model property of App\User, mixed>>, array<int, array<string, string>> given.'],
                34 => ['Parameter #1 $values of method Hypervel\Database\Eloquent\Relations\HasMany<App\Account,App\User>::fillAndInsert() expects array<int, array<model property of App\Account, mixed>>, array<int, array<string, string>> given.'],
            ],
        ];

        yield 'increment-each' => [
            __DIR__ . '/data/model-property-increment-each-l13-3.php',
            [
                5 => ['Parameter #1 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::incrementEach() expects array<model property of App\User, float|int|numeric-string>, array{foo: 1} given.'],
                7 => ['Parameter #2 $extra of method Hypervel\Database\Eloquent\Builder<App\User>::incrementEach() expects array<model property of App\User, mixed>, array<string, string> given.'],
                10 => ['Parameter #1 $columns of method Hypervel\Database\Eloquent\Builder<App\User>::decrementEach() expects array<model property of App\User, float|int|numeric-string>, array{foo: 1} given.'],
                12 => ['Parameter #2 $extra of method Hypervel\Database\Eloquent\Builder<App\User>::decrementEach() expects array<model property of App\User, mixed>, array<string, string> given.'],
                15 => ['Parameter #1 $columns of method Hypervel\Database\Eloquent\Relations\HasMany<App\Account,App\User>::incrementEach() expects array<model property of App\Account, float|int|numeric-string>, array{foo: 1} given.'],
                16 => ['Parameter #1 $columns of method Hypervel\Database\Eloquent\Relations\HasMany<App\Account,App\User>::decrementEach() expects array<model property of App\Account, float|int|numeric-string>, array{foo: 1} given.'],
            ],
        ];

        yield 'relation-increment-or-create' => [
            __DIR__ . '/data/model-property-relation-increment-or-create-l13-35.php',
            [
                5 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::incrementOrCreate() expects array<model property of App\Account, mixed>, array<string, string> given.'],
                7 => ['Parameter #2 $column of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::incrementOrCreate() expects model property of App\Account, string given.'],
                9 => ['Parameter #5 $extra of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::incrementOrCreate() expects array<model property of App\Account, mixed>, array<string, string> given.'],
                12 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Address,App\User,App\Address|null>::incrementOrCreate() expects array<model property of App\Address, mixed>, array<string, string> given.'],
                13 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Address,App\User,Hypervel\Database\Eloquent\Collection<int, App\Address>>::incrementOrCreate() expects array<model property of App\Address, mixed>, array<string, string> given.'],
                15 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\BelongsToMany<App\Role,App\User,Hypervel\Database\Eloquent\Relations\Pivot,string>::incrementOrCreate() expects array<model property of App\Role, mixed>, array<string, string> given.'],
                17 => ['Parameter #2 $column of method Hypervel\Database\Eloquent\Relations\BelongsToMany<App\Role,App\User,Hypervel\Database\Eloquent\Relations\Pivot,string>::incrementOrCreate() expects model property of App\Role, string given.'],
                18 => ['Parameter #5 $extra of method Hypervel\Database\Eloquent\Relations\BelongsToMany<App\Role,App\User,Hypervel\Database\Eloquent\Relations\Pivot,string>::incrementOrCreate() expects array<model property of App\Role, mixed>, array<string, string> given.'],
            ],
        ];

        yield 'relation-increment-or-create-through' => [
            __DIR__ . '/data/model-property-relation-increment-or-create-l13-37.php',
            [
                5 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrManyThrough<App\Transaction,App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Transaction>>::incrementOrCreate() expects array<model property of App\Transaction, mixed>, array<string, string> given.'],
                7 => ['Parameter #2 $column of method Hypervel\Database\Eloquent\Relations\HasOneOrManyThrough<App\Car,App\Mechanic,App\User,App\Car|null>::incrementOrCreate() expects model property of App\Car, string given.'],
                8 => [
                    'Parameter #2 $column of method Hypervel\Database\Eloquent\Relations\HasOneOrManyThrough<App\Car,App\Mechanic,App\User,App\Car|null>::incrementOrCreate() expects model property of App\Car, string given.',
                    'Parameter #5 $extra of method Hypervel\Database\Eloquent\Relations\HasOneOrManyThrough<App\Car,App\Mechanic,App\User,App\Car|null>::incrementOrCreate() expects array<model property of App\Car, mixed>, array<string, string> given.',
                ],
            ],
        ];

        yield 'model-save-options' => [
            __DIR__ . '/data/model-save-options.php',
            [
                5 => ['Parameter #1 $options of method Hypervel\Database\Eloquent\Model::save() expects array{touch?: bool|null, ...<string, mixed>}, array{touch: \'no\'} given.'],
                7 => ['Parameter #2 $options of method Hypervel\Database\Eloquent\Model::update() expects array{touch?: bool|null, ...<string, mixed>}, array{touch: \'no\'} given.'],
            ],
        ];

        // Hypervel: properties that only some union alternatives have may exist.
        yield [
            __DIR__ . '/data/model-property-model.php',
            [
                11 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Model::update() expects array<model property of static(ModelPropertyModel\ModelPropertyOnModel), mixed>, array<string, string> given.'],
                18 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Model::update() expects array<model property of App\Account|model property of App\User, mixed>, array<string, string> given.'],
                48 => ['Parameter #1 $property of method ModelPropertyModel\ModelPropertyCustomMethods::foo() expects model property of App\User, string given.'],
                66 => ['Parameter #1 $property of method ModelPropertyModel\ModelPropertyCustomMethodsInNormalClass::foo() expects model property of App\User, string given.'],
                92 => ['Parameter #1 $userModelProperty of function ModelPropertyModel\acceptsUserProperty expects model property of App\User, model property of App\Account given.'],
                105 => ['Parameter #1 $accountModelProperty of function ModelPropertyModel\acceptsUserOrAccountProperty expects model property of App\Account|App\User, string given.'],
            ],
        ];

        yield 'fill' => [
            __DIR__ . '/data/model-property-fill.php',
            [
                10 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Model::fill() expects array<model property of App\User, mixed>, array<string, string> given.'],
                11 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Model::forceFill() expects array<model property of App\User, mixed>, array<string, string> given.'],
                29 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Model::fill() expects array<model property of static(ModelPropertyFill\FillOnModel), mixed>, array<string, string> given.'],
                30 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Model::forceFill() expects array<model property of static(ModelPropertyFill\FillOnModel), mixed>, array<string, string> given.'],
            ],
        ];

        // Hypervel: createQuietly() and createMany() check the attributes too.
        yield [
            __DIR__ . '/data/model-property-model-factory.php',
            [
                7 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Factories\Factory<App\User>::createOne() expects array<model property of App\User, mixed>|(callable(array<string, mixed>): array<string, mixed>), array{foo: \'bar\'} given.'],
                8 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Factories\Factory<App\User>::createQuietly() expects array<model property of App\User, mixed>|(callable(array<string, mixed>): array<string, mixed>), array{foo: \'bar\'} given.'],
                9 => ['Parameter #1 $records of method Hypervel\Database\Eloquent\Factories\Factory<App\User>::createMany() expects int|iterable<int, array<model property of App\User, mixed>>|null, array<int, array<string, string>> given.'],
            ],
        ];

        yield [
            __DIR__ . '/data/model-property-relation.php',
            [
                4 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Relations\HasMany<App\Account,App\User>::where() expects array<int|model property of App\Account, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\Account, \'foo\' given.'],
                5 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::create() expects array<model property of App\Account, mixed>, array<string, string> given.'],
                6 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::firstOrNew() expects array<model property of App\Account, mixed>, array<string, string> given.'],
                7 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::firstOrCreate() expects array<model property of App\Account, mixed>, array<string, string> given.'],
                8 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::updateOrCreate() expects array<model property of App\Account, mixed>, array<string, string> given.'],
                10 => ['Parameter #1 $column of method Hypervel\Database\Eloquent\Relations\BelongsToMany<App\Post,App\User,Hypervel\Database\Eloquent\Relations\Pivot,string>::where() expects array<int|model property of App\Post, mixed>|(Closure(Hypervel\Database\Query\Builder): mixed)|Hypervel\Contracts\Database\Query\Expression|Hypervel\Database\Eloquent\Builder<*>|Hypervel\Database\Eloquent\Relations\Relation<*, *, *>|Hypervel\Database\Query\Builder|model property of App\Post, \'foo\' given.'],
                12 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::createOrFirst() expects array<model property of App\Account, mixed>, array<string, string> given.'],
            ],
        ];

        yield 'relation-create-variants' => [
            __DIR__ . '/data/model-property-relation-create-variants.php',
            [
                4 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::createQuietly() expects array<model property of App\Account, mixed>, array<string, string> given.'],
                5 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::forceCreate() expects array<model property of App\Account, mixed>, array<string, string> given.'],
                6 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::forceCreateQuietly() expects array<model property of App\Account, mixed>, array<string, string> given.'],
                7 => ['Parameter #1 $records of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::createManyQuietly() expects iterable<array<model property of App\Account, mixed>>, array<int, array<string, string>> given.'],
            ],
        ];

        yield 'relation-force-create-many' => [
            __DIR__ . '/data/model-property-force-create-many-l12-8.php',
            [
                6 => ['Parameter #1 $records of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::forceCreateMany() expects iterable<array<model property of App\Account, mixed>>, array<int, array<string, string>> given.'],
                7 => ['Parameter #1 $records of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::forceCreateManyQuietly() expects iterable<array<model property of App\Account, mixed>>, array<int, array<string, string>> given.'],
                8 => ['Parameter #1 $records of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Address,App\User,Hypervel\Database\Eloquent\Collection<int, App\Address>>::forceCreateMany() expects iterable<array<model property of App\Address, mixed>>, array<int, array<string, string>> given.'],
                23 => ['Parameter #1 $records of method Hypervel\Database\Eloquent\Relations\HasOneOrMany<App\Account,App\User,Hypervel\Database\Eloquent\Collection<int, App\Account>>::forceCreateMany() expects iterable<array<model property of App\Account, mixed>>, array<int, array<string, string>> given.'],
            ],
        ];

        yield [
            __DIR__ . '/data/model-property-static-call.php',
            [
                10 => ['Parameter #1 $attributes of static method App\User::create() expects array<model property of App\User, mixed>, array<string, string> given.'],
                14 => ['Parameter #1 $attributes of static method App\User::create() expects array<model property of App\User, mixed>, array<string, string> given.'],
                26 => ['Parameter #1 $attributes of static method ModelPropertyStaticCall\ModelPropertyStaticCallsInClass::create() expects array<model property of static(ModelPropertyStaticCall\ModelPropertyStaticCallsInClass), mixed>, array<string, string> given.'],
                34 => ['Parameter #1 $attributes of static method ModelPropertyStaticCall\ModelPropertyStaticCallsInClass::create() expects array<model property of static(ModelPropertyStaticCall\ModelPropertyStaticCallsInClass), mixed>, array<string, string> given.'],
            ],
        ];

        yield [
            __DIR__ . '/data/collection-of-type.php',
            [
                36 => ['Parameter #1 $accounts of method CollectionOfType\CollectionOfTypeTest::acceptsAccountCollection() expects App\AccountCollection<(int|string), App\Account>, Hypervel\Database\Eloquent\Collection<int, App\User> given.'],
                39 => ['Parameter #1 $users of method CollectionOfType\CollectionOfTypeTest::acceptsUserCollection() expects Hypervel\Database\Eloquent\Collection<(int|string), App\User>, App\AccountCollection<int, App\Account> given.'],
                69 => ['Parameter #1 $collection of method CollectionOfType\GenericTest::acceptsAccountCollection() expects App\AccountCollection<(int|string), App\Account>, Hypervel\Database\Eloquent\Collection<(int|string), App\User> given.'],
            ],
        ];

        yield [
            __DIR__ . '/data/builder-of-type.php',
            [
                44 => ['Parameter #1 $accountQuery of method BuilderOfType\BuilderOfTypeTest::acceptsAccountBuilder() expects Hypervel\Database\Eloquent\Builder<App\Account>, Hypervel\Database\Eloquent\Builder<App\User> given.'],
                47 => ['Parameter #1 $userQuery of method BuilderOfType\BuilderOfTypeTest::acceptsUserBuilder() expects Hypervel\Database\Eloquent\Builder<App\User>, Hypervel\Database\Eloquent\Builder<App\Account> given.'],
                50 => ['Parameter #1 $userQuery of method BuilderOfType\BuilderOfTypeTest::acceptsUserBuilder() expects Hypervel\Database\Eloquent\Builder<App\User>, App\ChildTeamBuilder given.'],
                89 => ['Parameter #1 $builder of method BuilderOfType\GenericTest::acceptsAccountBuilder() expects Hypervel\Database\Eloquent\Builder<App\Account>, Hypervel\Database\Eloquent\Builder<App\User> given.'],
            ],
        ];

        yield [
            __DIR__ . '/data/model-property-mutator-and-casting.php',
            [
                24 => ['Parameter #1 $lineOne of class ModelPropertyMutatorAndCasting\Address constructor expects string, mixed given.'],
                25 => ['Parameter #2 $lineTwo of class ModelPropertyMutatorAndCasting\Address constructor expects string, mixed given.'],
            ],
        ];

        yield [
            __DIR__ . '/../Fixtures/Integration/view-helper.php',
            [
                13 => ['Parameter #1 $view of function view expects view-string|null, string given.'],
            ],
        ];

        // Hypervel: each accessor direction follows Eloquent's precedence, falling back to the attribute's column and casts.
        yield [
            __DIR__ . '/data/model-accessors.php',
            [
                81 => ['Property ModelAccessors\AccessorModel::$name (string) does not accept int.'],
                86 => ['Property ModelAccessors\AccessorModel::$email (int) does not accept string.'],
                91 => ['Property ModelAccessors\AccessorModel::$virtual (string) does not accept int.'],
                95 => ['Property ModelAccessors\AccessorModel::$password (int) does not accept string.'],
            ],
        ];

        // Hypervel: a stubbed repository keeps its source offset types instead of ArrayAccess's.
        yield [
            __DIR__ . '/data/repository-offsets.php',
            [
                14 => ['Parameter #1 $offset of method Hypervel\Config\Repository::offsetGet() expects int|string, stdClass given.'],
                15 => ['Parameter #1 $offset of method Hypervel\Config\Repository::offsetSet() expects int|string, stdClass given.'],
                18 => ['Parameter #1 $offset of method Hypervel\Cache\Repository::offsetGet() expects string|UnitEnum, stdClass given.'],
                19 => ['Parameter #1 $offset of method Hypervel\Cache\Repository::offsetExists() expects string|UnitEnum, int given.'],
            ],
        ];

        // Hypervel: a key that is not a literal may name a property, including in a non-empty array.
        yield [
            __DIR__ . '/data/model-property-dynamic-keys.php',
            [
                11 => ['Parameter #1 $attributes of method Hypervel\Database\Eloquent\Model::forceFill() expects array<model property of App\User, mixed>, array<string, string> given.'],
            ],
        ];

        // Hypervel: a builder-of parameter of an unknown relation model accepts any builder.
        yield [__DIR__ . '/data/unknown-relation-model.php'];

        // Hypervel: Builder::__callStatic() registers macros and mixins and runs only the builder's global macros,
        // while a model forwards static calls through an instance.
        yield [
            __DIR__ . '/data/builder-static-calls.php',
            [
                14 => ['Static call to instance method App\PostBuilder<App\Post>::globalCustomDatabaseQueryMacro().'],
                15 => ['Static call to instance method Hypervel\Database\Eloquent\Builder<Hypervel\Database\Eloquent\Model>::flushMacros().'],
            ],
        ];

        // Hypervel: optional() passes its callback only values that are not null.
        yield [__DIR__ . '/data/optional-callbacks.php'];

        // Hypervel: a higher-order proxy runs outside the item's class, so it reaches only public members.
        yield [
            __DIR__ . '/data/proxied-members.php',
            [
                30 => ['Call to private method secret() of class ProxiedMembers\Score.'],
                31 => ['Access to protected property Hypervel\Support\HigherOrderCollectionProxy<string, ProxiedMembers\Score, Hypervel\Support\Collection<int, ProxiedMembers\Score>>::$rank.'],
            ],
        ];
    }

    /**
     * Analyze the given file and return its errors.
     *
     * @see https://github.com/phpstan/phpstan-src/blob/c9772621c0bd6eab7e02fdaa03714bea239b372d/tests/PHPStan/Analyser/AnalyserIntegrationTest.php#L604-L622
     * @see https://github.com/phpstan/phpstan/discussions/6888#discussioncomment-2423613
     *
     * @param null|string[] $allAnalysedFiles
     *
     * @return Error[]
     *
     * @throws Throwable
     */
    private function runAnalyse(string $file, ?array $allAnalysedFiles = null): array
    {
        $file = $this->getFileHelper()->normalizePath($file);

        /** @var Analyser $analyser */
        $analyser = self::getContainer()->getByType(Analyser::class);

        /** @var FileHelper $fileHelper */
        $fileHelper = self::getContainer()->getByType(FileHelper::class);

        $errors = $analyser->analyse([$file], null, null, true, $allAnalysedFiles)->getErrors();

        foreach ($errors as $error) {
            $this->assertSame($fileHelper->normalizePath($file), $error->getFilePath());
        }

        return $errors;
    }

    /**
     * Assert that the file reports exactly the expected errors.
     *
     * Upstream only checks that each reported error is expected, so an expected
     * error that is no longer reported would pass.
     *
     * @param array<int, array<int, string>> $expectedErrors
     * @param Error[] $errors
     */
    private function assertSameErrorMessages(string $file, array $expectedErrors, array $errors): void
    {
        $actualErrors = [];

        foreach ($errors as $error) {
            $actualErrors[$error->getLine() ?? 0][] = $error->getMessage();
        }

        $normalize = static function (array $errorsByLine): array {
            ksort($errorsByLine);

            return array_map(static function (array $messages): array {
                sort($messages);

                return $messages;
            }, $errorsByLine);
        };

        $this->assertSame($normalize($expectedErrors), $normalize($actualErrors), sprintf('File %s reports different errors.', $file));
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [
            __DIR__ . '/../Type/data/config-check-model-properties.neon',
        ];
    }
}
