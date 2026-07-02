<?php

declare(strict_types=1);

namespace Hypervel\Passkeys\Support;

use Closure;
use Hypervel\Passkeys\Passkeys;
use Symfony\Component\Serializer\Normalizer\AbstractObjectNormalizer;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerInterface;
use UnexpectedValueException;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\CeremonyStep\CeremonyStepManager;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\Denormalizer\WebauthnSerializerFactory;

/**
 * This class provides a static interface to the webauthn-lib package, handling
 * serialization, deserialization, and validation of WebAuthn ceremonies.
 */
final class WebAuthn
{
    /**
     * The cached serializer instance.
     */
    private static ?SerializerInterface $serializer = null;

    /**
     * The cached attestation statement support manager.
     */
    private static ?AttestationStatementSupportManager $attestationStatementSupportManager = null;

    /**
     * The cached registration ceremony step manager.
     */
    private static ?CeremonyStepManager $creationCeremony = null;

    /**
     * The cached authentication ceremony step manager.
     */
    private static ?CeremonyStepManager $requestCeremony = null;

    /** @var null|Closure(CeremonyStepManagerFactory): (null|CeremonyStepManagerFactory) */
    private static ?Closure $configureCeremonyStepManagerFactoryUsing = null;

    /**
     * Serialize data to JSON.
     */
    public static function toJson(mixed $data): string
    {
        return self::serializer()->serialize($data, 'json');
    }

    /**
     * Serialize data to a browser-facing array, omitting null values.
     *
     * @return array<array-key, mixed>
     */
    public static function toBrowserArray(mixed $data): array
    {
        $normalized = self::normalizer()->normalize($data, 'json', [
            AbstractObjectNormalizer::SKIP_NULL_VALUES => true,
        ]);

        if (! is_array($normalized)) {
            throw new UnexpectedValueException('Serialized WebAuthn data must normalize to an array.');
        }

        return $normalized;
    }

    /**
     * Deserialize JSON to a specific class.
     *
     * @template T of object
     *
     * @param class-string<T> $class
     * @return T
     */
    public static function fromJson(string $json, string $class): object
    {
        $object = self::serializer()->deserialize($json, $class, 'json');

        if (! $object instanceof $class) {
            throw new UnexpectedValueException("Serialized WebAuthn data must deserialize to [{$class}].");
        }

        return $object;
    }

    /**
     * Create the attestation response validator for registration ceremonies.
     */
    public static function attestationValidator(): AuthenticatorAttestationResponseValidator
    {
        return AuthenticatorAttestationResponseValidator::create(
            ceremonyStepManager: Passkeys::hasRequestAwareAllowedOrigins()
                ? self::ceremonyStepManagerFactory()->creationCeremony()
                : self::$creationCeremony ??= self::ceremonyStepManagerFactory()->creationCeremony()
        );
    }

    /**
     * Create the assertion response validator for verification ceremonies.
     */
    public static function assertionValidator(): AuthenticatorAssertionResponseValidator
    {
        return AuthenticatorAssertionResponseValidator::create(
            ceremonyStepManager: Passkeys::hasRequestAwareAllowedOrigins()
                ? self::ceremonyStepManagerFactory()->requestCeremony()
                : self::$requestCeremony ??= self::ceremonyStepManagerFactory()->requestCeremony()
        );
    }

    /**
     * Configure the ceremony step manager factory.
     *
     * Boot-only. The callback persists in static state for the worker lifetime and affects every subsequent WebAuthn ceremony.
     *
     * @param null|(callable(CeremonyStepManagerFactory): (null|CeremonyStepManagerFactory)) $callback
     */
    public static function configureCeremonyStepManagerFactoryUsing(?callable $callback): void
    {
        self::$configureCeremonyStepManagerFactoryUsing = $callback === null
            ? null
            : Closure::fromCallable($callback);

        self::$creationCeremony = null;
        self::$requestCeremony = null;
    }

    /**
     * Get or create the ceremony step manager factory.
     */
    private static function ceremonyStepManagerFactory(): CeremonyStepManagerFactory
    {
        $factory = new CeremonyStepManagerFactory;
        $factory->setAllowedOrigins(Passkeys::allowedOrigins());
        $factory->setAttestationStatementSupportManager(self::attestationStatementSupportManager());

        if (self::$configureCeremonyStepManagerFactoryUsing instanceof Closure) {
            $configured = (self::$configureCeremonyStepManagerFactoryUsing)($factory);

            if ($configured instanceof CeremonyStepManagerFactory) {
                return $configured;
            }
        }

        return $factory;
    }

    /**
     * Get or create the attestation statement support manager.
     *
     * Only "none" attestation is registered, so we accept passkeys without
     * verifying hardware attestation certificates from the authenticator.
     */
    private static function attestationStatementSupportManager(): AttestationStatementSupportManager
    {
        if (! self::$attestationStatementSupportManager instanceof AttestationStatementSupportManager) {
            self::$attestationStatementSupportManager = AttestationStatementSupportManager::create();
            self::$attestationStatementSupportManager->add(NoneAttestationStatementSupport::create());
        }

        return self::$attestationStatementSupportManager;
    }

    /**
     * Get or create the serializer instance.
     */
    private static function serializer(): SerializerInterface
    {
        if (! self::$serializer instanceof SerializerInterface) {
            self::$serializer = (new WebauthnSerializerFactory(
                self::attestationStatementSupportManager()
            ))->create();
        }

        return self::$serializer;
    }

    /**
     * Get the serializer as a normalizer.
     */
    private static function normalizer(): NormalizerInterface
    {
        $serializer = self::serializer();

        if (! $serializer instanceof NormalizerInterface) {
            throw new UnexpectedValueException('The WebAuthn serializer must also normalize objects.');
        }

        return $serializer;
    }

    /**
     * Flush all static state.
     */
    public static function flushState(): void
    {
        self::$serializer = null;
        self::$attestationStatementSupportManager = null;
        self::$creationCeremony = null;
        self::$requestCeremony = null;
        self::$configureCeremonyStepManagerFactoryUsing = null;
    }
}
