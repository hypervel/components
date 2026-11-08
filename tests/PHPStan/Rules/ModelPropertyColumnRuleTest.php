<?php

declare(strict_types=1);

namespace Hypervel\Tests\PHPStan\Rules;

use Hypervel\PHPStan\Rules\ModelPropertyColumnRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/** @extends RuleTestCase<ModelPropertyColumnRule> */
class ModelPropertyColumnRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return self::getContainer()->getByType(ModelPropertyColumnRule::class);
    }

    public function testRule(): void
    {
        $builderColumns = "'*'|array<int, '*'|Hypervel\\Contracts\\Database\\Query\\Expression|model property of App\\User>|model property of App\\User";
        $userTip = 'Type #2 from the union: The given string should be a property of App\User, nonexistent given.';
        $userArrayTip = 'Type #2 from the union: Type #3 from the union: The given string should be a property of App\User, nonexistent given.';
        $userColumnTip = 'Column nonexistent is not a property of App\User.';

        $this->analyse([__DIR__ . '/data/model-property-columns.php'], [
            [
                'Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::pluck() expects Hypervel\Contracts\Database\Query\Expression|model property of App\User, string given.',
                45,
                $userTip,
            ],
            [
                "Parameter #2 \$columns of method Hypervel\\Database\\Eloquent\\Builder<App\\User>::paginate() expects {$builderColumns}, array{'id', 'nonexistent'} given.",
                48,
                $userArrayTip,
            ],
            [
                "Parameter #2 \$columns of method Hypervel\\Database\\Eloquent\\Builder<App\\User>::simplePaginate() expects {$builderColumns}, array{'nonexistent'} given.",
                50,
                $userArrayTip,
            ],
            [
                "Parameter #2 \$columns of method Hypervel\\Database\\Eloquent\\Builder<App\\User>::cursorPaginate() expects {$builderColumns}, 'nonexistent' given.",
                51,
                'Type #3 from the union: The given string should be a property of App\User, nonexistent given.',
            ],
            [
                'Parameter #1 $column of static method App\User::pluck() expects Hypervel\Contracts\Database\Query\Expression|model property of App\User, string given.',
                53,
                $userTip,
            ],
            [
                "Parameter #2 \$columns of static method App\\User::paginate() expects {$builderColumns}, array{'nonexistent'} given.",
                54,
                $userArrayTip,
            ],
            [
                'Parameter #1 $column of method Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>::pluck() expects Hypervel\Contracts\Database\Query\Expression|model property of App\Account, string given.',
                56,
                'Type #2 from the union: The given string should be a property of App\Account, nonexistent given.',
            ],
            [
                "Parameter #2 \$columns of method Hypervel\\Database\\Eloquent\\Relations\\BelongsToMany<App\\Role, App\\User, Hypervel\\Database\\Eloquent\\Relations\\Pivot, string>::paginate() expects array<int, '*'|Hypervel\\Contracts\\Database\\Query\\Expression|model property of App\\Role>, array{'nonexistent'} given.",
                57,
                'Type #3 from the union: The given string should be a property of App\Role, nonexistent given.',
            ],
            [
                "Parameter #2 \$columns of method Hypervel\\Database\\Eloquent\\Relations\\HasManyThrough<App\\Transaction, App\\Account, App\\User>::simplePaginate() expects array<int, '*'|Hypervel\\Contracts\\Database\\Query\\Expression|model property of App\\Transaction>, array{'nonexistent'} given.",
                58,
                'Type #3 from the union: The given string should be a property of App\Transaction, nonexistent given.',
            ],
            [
                'Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\SearchableUser>::pluck() expects Hypervel\Contracts\Database\Query\Expression|model property of App\SearchableUser, string given.',
                64,
                'Type #2 from the union: The given string should be a property of App\SearchableUser, nonexistent given.',
            ],
            [
                'Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User|ModelPropertyColumns\AbstractSubject>::pluck() expects Hypervel\Contracts\Database\Query\Expression|model property of App\User|ModelPropertyColumns\AbstractSubject, string given.',
                67,
                'Type #2 from the union: The given string should be a property of App\User|ModelPropertyColumns\AbstractSubject, nonexistent given.',
            ],
            [
                'Parameter #1 $column of static method App\User::pluck() expects Hypervel\Contracts\Database\Query\Expression|model property of App\User, string given.',
                70,
                $userTip,
            ],
            [
                'Call to an undefined method Hypervel\Database\Eloquent\Builder<App\User>::whereNameOrNonexistent().',
                105,
                $userColumnTip,
            ],
            [
                'Call to an undefined static method App\User::whereNonexistent().',
                106,
                $userColumnTip,
            ],
            [
                'Call to an undefined method App\User::whereNonexistent().',
                107,
                $userColumnTip,
            ],
            [
                'Call to an undefined method Hypervel\Database\Eloquent\Relations\HasMany<App\Account, App\User>::whereNonexistent().',
                108,
                'Column nonexistent is not a property of App\Account.',
            ],
            [
                'Call to an undefined method ModelPropertyColumns\WhereBuilder<App\User>::whereNonexistent().',
                109,
                $userColumnTip,
            ],
            [
                'Parameter #1 $column of method Hypervel\Database\Eloquent\Builder<App\User>::pluck() expects Hypervel\Contracts\Database\Query\Expression|model property of App\User, string given.',
                110,
                $userTip,
            ],
        ]);
    }

    /** @return string[] */
    public static function getAdditionalConfigFiles(): array
    {
        return [__DIR__ . '/../phpstan-tests.neon'];
    }
}
