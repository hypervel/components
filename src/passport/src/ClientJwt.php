<?php

declare(strict_types=1);

namespace Hypervel\Passport;

use Firebase\JWT\JWT;
use Firebase\JWT\SignatureInvalidException;
use SensitiveParameter;
use stdClass;

/**
 * @internal allow client clock skew without changing other Firebase JWT consumers
 */
class ClientJwt extends JWT
{
    /**
     * The clock allowance for client assertions and DPoP proofs, in seconds.
     *
     * @var int
     */
    public static $leeway = 60;

    /**
     * Reject malformed ECDSA encodings before verifying the JWT.
     */
    public static function decode(
        #[SensitiveParameter]
        string $jwt,
        #[SensitiveParameter]
        mixed $keyOrKeyArray,
        ?stdClass &$headers = null,
    ): stdClass {
        // @TODO Remove this override when the minimum firebase/php-jwt version validates
        // ECDSA signature widths and handles zero r/s without emitting PHP warnings.
        $parts = explode('.', $jwt, 4);

        if (count($parts) === 3) {
            $header = json_decode(static::urlsafeB64Decode($parts[0]), true);

            if (($header['alg'] ?? null) === 'ES256') {
                $signature = static::urlsafeB64Decode($parts[2]);

                if (strlen($signature) !== 64
                    || strspn($signature, "\0", 0, 32) === 32
                    || strspn($signature, "\0", 32, 32) === 32) {
                    throw new SignatureInvalidException('Signature verification failed');
                }
            }
        }

        return parent::decode($jwt, $keyOrKeyArray, $headers);
    }
}
