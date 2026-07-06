<?php

declare(strict_types=1);

$ttl = env('JWT_TTL', 120);
$refreshTtl = env('JWT_REFRESH_TTL', 20160);

return [
    /*
    |--------------------------------------------------------------------------
    | JWT Driver
    |--------------------------------------------------------------------------
    |
    | The driver you are using to encode, decode and sign your
    | JWT token, all the drivers must implement:
    | Hypervel\JWT\Contracts\ProviderContract::class
    |
    */

    'driver' => env('JWT_DRIVER', 'lcobucci'),

    /*
    |--------------------------------------------------------------------------
    | JWT Authentication Secret
    |--------------------------------------------------------------------------
    |
    | Don't forget to set this in your .env file, as it will be used to sign
    | your tokens. You may generate it using the jwt:secret command.
    |
    | Note: This will be used for Symmetric algorithms only (HMAC),
    | since RSA and ECDSA use a private/public key combo (See below).
    |
    */

    'secret' => env('JWT_SECRET'),

    /*
    |--------------------------------------------------------------------------
    | JWT Authentication Keys
    |--------------------------------------------------------------------------
    |
    | The algorithm you are using, will determine whether your tokens are
    | signed with a random string (defined in `JWT_SECRET`) or using the
    | following public & private keys.
    |
    | Symmetric Algorithms:
    | HS256, HS384 & HS512 will use `JWT_SECRET`.
    |
    | Asymmetric Algorithms:
    | RS256, RS384 & RS512 / ES256, ES384 & ES512 will use the keys below.
    |
    */

    'keys' => [
        /*
        |--------------------------------------------------------------------------
        | Public Key
        |--------------------------------------------------------------------------
        |
        | A path or resource to your public key.
        |
        | E.g. 'file://path/to/public/key'
        |
        */

        'public' => env('JWT_PUBLIC_KEY'),

        /*
        |--------------------------------------------------------------------------
        | Private Key
        |--------------------------------------------------------------------------
        |
        | A path or resource to your private key.
        |
        | E.g. 'file://path/to/private/key'
        |
        */

        'private' => env('JWT_PRIVATE_KEY'),

        /*
        |--------------------------------------------------------------------------
        | Passphrase
        |--------------------------------------------------------------------------
        |
        | The passphrase for your private key. Can be null if none set.
        |
        */

        'passphrase' => env('JWT_PASSPHRASE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | JWT time to live
    |--------------------------------------------------------------------------
    |
    | Specify the length of time (in minutes) that the token will be valid for.
    | Defaults to 2 hours.
    |
    | You can also set this to null, to yield a never expiring token.
    | Some people may want this behaviour for e.g. a mobile app.
    | This is not particularly recommended, so make sure you have appropriate
    | systems in place to revoke the token if necessary.
    | Notice: If you set this to null you should remove 'exp' element from 'required_claims' list.
    |
    */

    'ttl' => $ttl === null ? null : (int) $ttl,

    /*
    |--------------------------------------------------------------------------
    | Refresh time to live
    |--------------------------------------------------------------------------
    |
    | Specify the length of time (in minutes) that the token can be refreshed
    | within. I.E. The user can refresh their token within a 2 week window of
    | the original token being created until they must re-authenticate.
    | Defaults to 2 weeks.
    |
    | You can also set this to null, to yield an infinite refresh time.
    | Some may want this instead of never expiring tokens for e.g. a mobile app.
    | This is not particularly recommended, so make sure you have appropriate
    | systems in place to revoke the token if necessary.
    |
    */

    'refresh_ttl' => $refreshTtl === null ? null : (int) $refreshTtl,

    /*
    |--------------------------------------------------------------------------
    | Issuer
    |--------------------------------------------------------------------------
    |
    | The issuer to add to newly generated tokens.
    |
    */

    'issuer' => env('JWT_ISSUER'),

    /*
    |--------------------------------------------------------------------------
    | JWT hashing algorithm
    |--------------------------------------------------------------------------
    |
    | Specify the hashing algorithm that will be used to sign the token.
    |
    */

    'algo' => env('JWT_ALGO', Hypervel\JWT\Providers\Provider::ALGO_HS256),

    /*
    |--------------------------------------------------------------------------
    | Validations
    |--------------------------------------------------------------------------
    |
    | Specify the default validations for JWT tokens.
    |
    */
    'validations' => [
        \Hypervel\JWT\Validations\RequiredClaims::class,
        \Hypervel\JWT\Validations\ExpiredClaim::class,
        \Hypervel\JWT\Validations\IssuerClaim::class,
        \Hypervel\JWT\Validations\IssuedAtClaim::class,
        \Hypervel\JWT\Validations\NotBeforeClaim::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Required Claims
    |--------------------------------------------------------------------------
    |
    | Specify the required claims that must exist in any token.
    | A TokenInvalidException will be thrown if any of these claims are not
    | present in the payload.
    |
    */

    'required_claims' => [
        // 'iss',
        'iat',
        // 'exp',
        // 'nbf',
        'sub',
        // 'jti',
    ],

    /*
    |--------------------------------------------------------------------------
    | Persistent Claims
    |--------------------------------------------------------------------------
    |
    | Specify the claim keys to be persisted when refreshing a token.
    | `sub` and `iat` will automatically be persisted, in
    | addition to the these claims.
    |
    | Note: If a claim does not exist then it will be ignored.
    |
    */

    'persistent_claims' => [
        // 'foo',
        // 'bar',
    ],

    /*
    |--------------------------------------------------------------------------
    | Leeway
    |--------------------------------------------------------------------------
    |
    | This property gives the jwt timestamp claims some "leeway".
    | Meaning that if you have any unavoidable slight clock skew on
    | any of your servers then this will afford you some level of cushioning.
    |
    | This applies to the claims `iat`, `nbf` and `exp`.
    |
    | Specify in seconds - only if you know you need it.
    |
    */

    'leeway' => (int) env('JWT_LEEWAY', 0),

    /*
    |--------------------------------------------------------------------------
    | Blacklist Enabled
    |--------------------------------------------------------------------------
    |
    | In order to invalidate tokens, you must have the blacklist enabled.
    | If you do not want or need this functionality, then set this to false.
    |
    */

    'blacklist_enabled' => env('JWT_BLACKLIST_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Refresh Issued At
    |--------------------------------------------------------------------------
    |
    | When enabled, refreshed tokens receive a fresh iat claim. When disabled,
    | refreshed tokens keep the original iat claim.
    |
    */

    'refresh_iat' => env('JWT_REFRESH_IAT', false),

    /*
    |--------------------------------------------------------------------------
    | Subject Locking
    |--------------------------------------------------------------------------
    |
    | When enabled, tokens include a provider model hash to prevent the same
    | subject ID from authenticating against a different provider model.
    |
    */

    'lock_subject' => env('JWT_LOCK_SUBJECT', true),

    /*
    |--------------------------------------------------------------------------
    | Token Parser
    |--------------------------------------------------------------------------
    |
    | Configure the request input key and ordered parser chain used to extract
    | JWT tokens from incoming requests.
    |
    */

    'token' => env('JWT_TOKEN', 'token'),

    'parser' => [
        \Hypervel\JWT\Http\Parser\AuthHeaders::class,
    ],

    /*
    | -------------------------------------------------------------------------
    | Blacklist Grace Period
    | -------------------------------------------------------------------------
    |
    | When multiple concurrent requests are made with the same JWT,
    | it is possible that some of them fail, due to token regeneration
    | on every request.
    |
    | Set grace period in seconds to prevent parallel request failure.
    |
    */

    'blacklist_grace_period' => (int) env('JWT_BLACKLIST_GRACE_PERIOD', 0),

    /*
    | -------------------------------------------------------------------------
    | Refresh time to live of blacklist
    | -------------------------------------------------------------------------
    |
    | Number of minutes from issue date in which a JWT can be refreshed.
    |
    */

    'blacklist_refresh_ttl' => (int) env('JWT_BLACKLIST_REFRESH_TTL', 20160),

    /*
    |--------------------------------------------------------------------------
    | Providers
    |--------------------------------------------------------------------------
    |
    | Specify the various providers used throughout the package.
    |
    */

    'providers' => [
        /*
        |--------------------------------------------------------------------------
        | JWT Provider
        |--------------------------------------------------------------------------
        |
        | Specify the provider that is used to create and decode the tokens.
        |
        */

        'jwt' => Hypervel\JWT\Providers\Lcobucci::class,

        /*
        |--------------------------------------------------------------------------
        | Storage Provider
        |--------------------------------------------------------------------------
        |
        | Specify the provider that is used to store tokens in the blacklist.
        | The default tagged-cache storage requires a taggable default cache
        | store; with node-local stack tiers, blacklist visibility is bounded
        | by the upper tier's TTL.
        |
        */

        'storage' => Hypervel\JWT\Storage\TaggedCache::class,
    ],
];
