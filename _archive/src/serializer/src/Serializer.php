<?php

declare(strict_types=1);

namespace Hypervel\Serializer;

use ArrayObject;
use Countable;
use Hypervel\Contracts\Serializer\NormalizerInterface as Normalizer;
use Symfony\Component\Serializer\Encoder\ChainDecoder;
use Symfony\Component\Serializer\Encoder\ChainEncoder;
use Symfony\Component\Serializer\Encoder\ContextAwareDecoderInterface;
use Symfony\Component\Serializer\Encoder\ContextAwareEncoderInterface;
use Symfony\Component\Serializer\Encoder\DecoderInterface;
use Symfony\Component\Serializer\Encoder\EncoderInterface;
use Symfony\Component\Serializer\Exception\InvalidArgumentException;
use Symfony\Component\Serializer\Exception\LogicException;
use Symfony\Component\Serializer\Exception\NotEncodableValueException;
use Symfony\Component\Serializer\Exception\NotNormalizableValueException;
use Symfony\Component\Serializer\Normalizer\DenormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Symfony\Component\Serializer\SerializerAwareInterface;
use Symfony\Component\Serializer\SerializerInterface;
use Traversable;

use function gettype;
use function is_array;
use function is_object;
use function is_resource;
use function is_scalar;

/**
 * Serializer serializes and deserializes data.
 *
 * objects are turned into arrays by normalizers.
 * arrays are turned into various output formats by encoders.
 *
 *     $serializer->serialize($obj, 'xml')
 *     $serializer->decode($data, 'xml')
 *     $serializer->denormalize($data, 'Class', 'xml')
 */
class Serializer implements Normalizer, SerializerInterface, NormalizerInterface, DenormalizerInterface, ContextAwareEncoderInterface, ContextAwareDecoderInterface
{
    protected const SCALAR_TYPES = [
        'int' => true,
        'bool' => true,
        'float' => true,
        'string' => true,
    ];

    protected ChainEncoder $encoder;

    protected ChainDecoder $decoder;

    /** @var array<DenormalizerInterface|NormalizerInterface> */
    protected array $normalizers = [];

    /** @var array<string, array<string, array<int, bool>>> */
    protected array $denormalizerCache = [];

    /** @var array<string, array<string, array<int, bool>>> */
    protected array $normalizerCache = [];

    /**
     * Create a new serializer instance.
     *
     * @param array<DenormalizerInterface|NormalizerInterface> $normalizers
     * @param array<DecoderInterface|EncoderInterface> $encoders
     */
    public function __construct(array $normalizers = [], array $encoders = [])
    {
        foreach ($normalizers as $normalizer) {
            if ($normalizer instanceof SerializerAwareInterface) {
                $normalizer->setSerializer($this);
            }

            if ($normalizer instanceof DenormalizerAwareInterface) {
                $normalizer->setDenormalizer($this);
            }

            if ($normalizer instanceof NormalizerAwareInterface) {
                $normalizer->setNormalizer($this);
            }

            if (! ($normalizer instanceof NormalizerInterface || $normalizer instanceof DenormalizerInterface)) { /* @phpstan-ignore booleanOr.alwaysTrue, instanceof.alwaysTrue */
                throw new InvalidArgumentException(sprintf('The class "%s" neither implements "%s" nor "%s".', get_debug_type($normalizer), NormalizerInterface::class, DenormalizerInterface::class));
            }
        }
        $this->normalizers = $normalizers;

        $decoders = [];
        $realEncoders = [];
        foreach ($encoders as $encoder) {
            if ($encoder instanceof SerializerAwareInterface) {
                $encoder->setSerializer($this);
            }
            if ($encoder instanceof DecoderInterface) {
                $decoders[] = $encoder;
            }
            if ($encoder instanceof EncoderInterface) {
                $realEncoders[] = $encoder;
            }

            if (! ($encoder instanceof EncoderInterface || $encoder instanceof DecoderInterface)) { /* @phpstan-ignore booleanOr.alwaysTrue, instanceof.alwaysTrue */
                throw new InvalidArgumentException(sprintf('The class "%s" neither implements "%s" nor "%s".', get_debug_type($encoder), EncoderInterface::class, DecoderInterface::class));
            }
        }
        $this->encoder = new ChainEncoder($realEncoders);
        $this->decoder = new ChainDecoder($decoders);
    }

    /**
     * Serialize data into the given format.
     */
    final public function serialize(mixed $data, string $format, array $context = []): string
    {
        if (! $this->supportsEncoding($format, $context)) {
            throw new NotEncodableValueException(sprintf('Serialization for the format "%s" is not supported.', $format));
        }

        if ($this->encoder->needsNormalization($format, $context)) {
            $data = $this->normalize($data, $format, $context);
        }

        return $this->encode($data, $format, $context);
    }

    /**
     * Deserialize data from the given format into the specified type.
     */
    final public function deserialize(mixed $data, string $type, string $format, array $context = []): mixed
    {
        if (! $this->supportsDecoding($format, $context)) {
            throw new NotEncodableValueException(sprintf('Deserialization for the format "%s" is not supported.', $format));
        }

        $data = $this->decode($data, $format, $context);

        return $this->denormalize($data, $type, $format, $context);
    }

    /**
     * Normalize data into a set of arrays/scalars.
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|ArrayObject|null
    {
        // If a normalizer supports the given data, use it
        if ($normalizer = $this->getNormalizer($object, $format, $context)) {
            return $normalizer->normalize($object, $format, $context);
        }

        if ($object === null || is_scalar($object)) {
            return $object;
        }

        if (is_array($object) || $object instanceof Traversable) {
            if ($object instanceof Countable && $object->count() === 0) {
                return new ArrayObject();
            }

            $normalized = [];
            foreach ($object as $key => $val) {
                $normalized[$key] = $this->normalize($val, $format, $context);
            }

            return $normalized;
        }

        if (is_object($object)) {
            if (! $this->normalizers) {
                throw new LogicException('You must register at least one normalizer to be able to normalize objects.');
            }

            throw new NotNormalizableValueException(sprintf('Could not normalize object of type "%s", no supporting normalizer found.', get_debug_type($object)));
        }

        throw new NotNormalizableValueException('An unexpected value could not be normalized: ' . (! is_resource($object) ? var_export($object, true) : sprintf('%s resource', get_resource_type($object))));
    }

    /**
     * Denormalize data back into an object of the given class.
     *
     * @throws NotNormalizableValueException
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (isset(self::SCALAR_TYPES[$type])) {
            if (is_scalar($data)) {
                return match ($type) {
                    'int' => (int) $data,
                    'bool' => (bool) $data,
                    'float' => (float) $data,
                    'string' => (string) $data,
                };
            }
        }

        if (! $this->normalizers) {
            throw new LogicException('You must register at least one normalizer to be able to denormalize objects.');
        }

        if ($normalizer = $this->getDenormalizer($data, $type, $format, $context)) {
            return $normalizer->denormalize($data, $type, $format, $context);
        }

        throw new NotNormalizableValueException(sprintf('Could not denormalize object of type "%s", no supporting normalizer found.', $type));
    }

    /**
     * Check whether the given data is supported for normalization.
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $this->getNormalizer($data, $format, $context) !== null;
    }

    /**
     * Check whether the given type is supported for denormalization.
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return isset(self::SCALAR_TYPES[$type]) || $this->getDenormalizer($data, $type, $format, $context) !== null;
    }

    /**
     * Return the types supported by this serializer.
     *
     * @return array<'*'|'object'|class-string|string, null|bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return ['*' => false];
    }

    /**
     * Encode data into the given format.
     */
    final public function encode(mixed $data, string $format, array $context = []): string
    {
        return $this->encoder->encode($data, $format, $context);
    }

    /**
     * Decode data from the given format.
     */
    final public function decode(string $data, string $format, array $context = []): mixed
    {
        return $this->decoder->decode($data, $format, $context);
    }

    /**
     * Check whether the given format is supported for encoding.
     */
    public function supportsEncoding(string $format, array $context = []): bool
    {
        return $this->encoder->supportsEncoding($format, $context);
    }

    /**
     * Check whether the given format is supported for decoding.
     */
    public function supportsDecoding(string $format, array $context = []): bool
    {
        return $this->decoder->supportsDecoding($format, $context);
    }

    /**
     * Return a matching normalizer.
     */
    private function getNormalizer(mixed $data, ?string $format, array $context): ?NormalizerInterface
    {
        $type = is_object($data) ? $data::class : 'native-' . gettype($data);
        $genericType = is_object($data) ? 'object' : '*';

        if (! isset($this->normalizerCache[$format ?? ''][$type])) {
            $this->normalizerCache[$format ?? ''][$type] = [];

            foreach ($this->normalizers as $k => $normalizer) {
                if (! $normalizer instanceof NormalizerInterface) {
                    continue;
                }

                $supportedTypes = $normalizer->getSupportedTypes($format);

                foreach ($supportedTypes as $supportedType => $isCacheable) {
                    if (in_array($supportedType, ['*', 'object'], true)
                        || $type !== $supportedType && ($genericType !== 'object' || ! is_subclass_of($type, $supportedType))
                    ) {
                        continue;
                    }

                    if ($isCacheable === null) {
                        unset($supportedTypes['*'], $supportedTypes['object']);
                    } elseif ($this->normalizerCache[$format ?? ''][$type][$k] = $isCacheable && $normalizer->supportsNormalization($data, $format, $context)) {
                        break 2;
                    }

                    break;
                }

                if (null === $isCacheable = $supportedTypes[array_key_exists($genericType, $supportedTypes) ? $genericType : '*'] ?? null) {
                    continue;
                }

                if ($this->normalizerCache[$format ?? ''][$type][$k] ??= $isCacheable && $normalizer->supportsNormalization($data, $format, $context)) {
                    break;
                }
            }
        }

        foreach ($this->normalizerCache[$format ?? ''][$type] as $k => $cached) {
            $normalizer = $this->normalizers[$k];
            if ($cached || $normalizer->supportsNormalization($data, $format, $context)) {
                return $normalizer;
            }
        }

        return null;
    }

    /**
     * Return a matching denormalizer.
     */
    private function getDenormalizer(mixed $data, string $class, ?string $format, array $context): ?DenormalizerInterface
    {
        if (! isset($this->denormalizerCache[$format ?? ''][$class])) {
            $this->denormalizerCache[$format ?? ''][$class] = [];
            $genericType = class_exists($class) || interface_exists($class, false) ? 'object' : '*';

            foreach ($this->normalizers as $k => $normalizer) {
                if (! $normalizer instanceof DenormalizerInterface) {
                    continue;
                }

                $supportedTypes = $normalizer->getSupportedTypes($format);

                $doesClassRepresentCollection = str_ends_with($class, '[]');

                foreach ($supportedTypes as $supportedType => $isCacheable) {
                    if (in_array($supportedType, ['*', 'object'], true)
                        || $class !== $supportedType && ($genericType !== 'object' || ! is_subclass_of($class, $supportedType))
                        && ! ($doesClassRepresentCollection && str_ends_with($supportedType, '[]') && is_subclass_of(strstr($class, '[]', true), strstr($supportedType, '[]', true)))
                    ) {
                        continue;
                    }

                    if ($isCacheable === null) {
                        unset($supportedTypes['*'], $supportedTypes['object']);
                    } elseif ($this->denormalizerCache[$format ?? ''][$class][$k] = $isCacheable && $normalizer->supportsDenormalization(null, $class, $format, $context)) {
                        break 2;
                    }

                    break;
                }

                if (null === $isCacheable = $supportedTypes[array_key_exists($genericType, $supportedTypes) ? $genericType : '*'] ?? null) {
                    continue;
                }

                if ($this->denormalizerCache[$format ?? ''][$class][$k] ??= $isCacheable && $normalizer->supportsDenormalization(null, $class, $format, $context)) {
                    break;
                }
            }
        }

        foreach ($this->denormalizerCache[$format ?? ''][$class] as $k => $cached) {
            $normalizer = $this->normalizers[$k];
            if ($cached || $normalizer->supportsDenormalization($data, $class, $format, $context)) {
                return $normalizer;
            }
        }

        return null;
    }
}
