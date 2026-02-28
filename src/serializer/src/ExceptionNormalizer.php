<?php

declare(strict_types=1);

namespace Hypervel\Serializer;

use ArrayObject;
use Doctrine\Instantiator\Instantiator;
use ReflectionException;
use ReflectionProperty;
use RuntimeException;
use Serializable;
use Symfony\Component\Serializer\Normalizer\DenormalizerInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;
use Throwable;
use TypeError;

class ExceptionNormalizer implements NormalizerInterface, DenormalizerInterface
{
    protected ?Instantiator $instantiator = null;

    /**
     * Denormalize data back into a throwable instance.
     */
    public function denormalize(mixed $data, string $type, ?string $format = null, array $context = []): mixed
    {
        if (is_string($data)) {
            $exception = unserialize($data);
            if ($exception instanceof Throwable) {
                return $exception;
            }

            // Retry handle it if the exception not instanceof \Throwable.
            $data = $exception;
        }
        if (is_array($data) && isset($data['message'], $data['code'])) {
            try {
                $exception = $this->getInstantiator()->instantiate($type);
                foreach (['code', 'message', 'file', 'line'] as $attribute) {
                    if (isset($data[$attribute])) {
                        $property = new ReflectionProperty($type, $attribute);
                        $property->setValue($exception, $data[$attribute]);
                    }
                }
                return $exception;
            } catch (ReflectionException) {
                return new RuntimeException(sprintf(
                    'Bad data %s: %s',
                    $data['class'],
                    $data['message']
                ), $data['code']);
            } catch (TypeError) {
                return new RuntimeException(sprintf(
                    'Uncaught data %s: %s',
                    $data['class'],
                    $data['message']
                ), $data['code']);
            }
        }

        return new RuntimeException('Bad data data: ' . json_encode($data));
    }

    /**
     * Check whether the given class is a throwable for denormalization.
     */
    public function supportsDenormalization(mixed $data, string $type, ?string $format = null, array $context = []): bool
    {
        return class_exists($type) && is_a($type, Throwable::class, true);
    }

    /**
     * Normalize a throwable into an array or serialized string.
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array|ArrayObject|bool|float|int|string|null
    {
        if ($object instanceof Serializable) {
            return serialize($object);
        }
        /* @var Throwable $object */
        return [
            'message' => $object->getMessage(),
            'code' => $object->getCode(),
            'file' => $object->getFile(),
            'line' => $object->getLine(),
        ];
    }

    /**
     * Check whether the given data is a throwable for normalization.
     */
    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        return $data instanceof Throwable;
    }

    /**
     * Return the types supported by this normalizer.
     *
     * @return array<'*'|'object'|class-string|string, null|bool>
     */
    public function getSupportedTypes(?string $format): array
    {
        return ['object' => static::class === __CLASS__];
    }

    /**
     * Get the Doctrine instantiator instance.
     */
    protected function getInstantiator(): Instantiator
    {
        if ($this->instantiator instanceof Instantiator) {
            return $this->instantiator;
        }

        return $this->instantiator = new Instantiator();
    }
}
