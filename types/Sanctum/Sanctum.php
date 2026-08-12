<?php

declare(strict_types=1);

use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Auth\User;
use Hypervel\Sanctum\HasApiTokens;
use Hypervel\Sanctum\PersonalAccessToken;
use Hypervel\Sanctum\Sanctum;

use function PHPStan\Testing\assertType;

enum SanctumAbility: string
{
    case Read = 'read';
}

class SanctumTypeUser extends User
{
    use HasApiTokens;
}

class SanctumTraitOnlyModel extends Model
{
    use HasApiTokens;
}

class SanctumTypeToken extends PersonalAccessToken
{
}

$user = new SanctumTypeUser;

assertType(
    SanctumTypeUser::class,
    Sanctum::actingAs($user, [SanctumAbility::Read]),
);

Sanctum::usePersonalAccessTokenModel(SanctumTypeToken::class);

assertType('class-string<Hypervel\Sanctum\PersonalAccessToken>', Sanctum::personalAccessTokenModel());
assertType(
    'Hypervel\Sanctum\PersonalAccessTokenRelation<SanctumTraitOnlyModel>',
    (new SanctumTraitOnlyModel)->tokens(),
);
assertType(
    'Hypervel\Sanctum\PersonalAccessToken|null',
    (new SanctumTraitOnlyModel)->currentAccessToken(),
);
assertType(
    'Hypervel\Sanctum\NewAccessToken',
    (new SanctumTraitOnlyModel)->createToken('example', [SanctumAbility::Read]),
);
