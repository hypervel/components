<?php

declare(strict_types=1);

namespace Hypervel\Notifications\Support;

use Closure;
use Error;
use Exception;
use GuzzleHttp\Psr7\Request as PsrRequest;
use GuzzleHttp\Psr7\Response as PsrResponse;
use GuzzleHttp\Psr7\Stream;
use GuzzleHttp\TransferStats;
use Hypervel\Http\Client\Response;
use Laravel\SerializableClosure\SerializableClosure;
use LogicException;
use Psr\Http\Message\StreamInterface;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;
use RuntimeException;
use Serializable;
use SplObjectStorage;
use Throwable;

/**
 * Preserve notification transport objects across serialization without serializing live streams.
 *
 * @internal
 * @phpstan-type StreamRecord array{kind: 'stream', closed: true, metadata: array<array-key, mixed>}|array{kind: 'stream', closed: false, metadata: array<array-key, mixed>, uri: string, body: string, position: int, eof: bool, size: int|null}
 * @phpstan-type TransportRecord array{kind: 'object', class: class-string, state: array<string, mixed>}|array{kind: 'closure', closure: SerializableClosure}|StreamRecord
 */
class TransportSnapshot
{
    /** @var array<int, TransportRecord> */
    private array $nodes = [];

    private TransportReference $root;

    /**
     * Create an operation-owned snapshot.
     */
    private function __construct()
    {
    }

    /**
     * Capture supported transport state and retain native serialization for other values.
     */
    public static function capture(mixed $value): mixed
    {
        if (! is_object($value) || ! self::supports($value)) {
            return $value;
        }

        $snapshot = new self;
        /** @var TransportReference $reference */
        $reference = $snapshot->encode($value, new SplObjectStorage);
        $snapshot->root = $reference;

        return $snapshot;
    }

    /**
     * Determine whether an object has a supported transport representation.
     */
    private static function supports(object $value): bool
    {
        if ($value::class === Stream::class) {
            return true;
        }

        return ! self::hasSerializationContract($value)
            && ($value instanceof Throwable || $value instanceof Response
                || in_array($value::class, [PsrRequest::class, PsrResponse::class, TransferStats::class], true));
    }

    /**
     * Respect an object's native serialization contract.
     */
    private static function hasSerializationContract(object $value): bool
    {
        return $value instanceof Serializable
            || method_exists($value, '__serialize')
            || method_exists($value, '__unserialize')
            || method_exists($value, '__sleep')
            || (method_exists($value, '__wakeup') && ! (new ReflectionMethod($value, '__wakeup'))->isInternal());
    }

    /**
     * Encode direct transport members while preserving shared object references.
     *
     * @param SplObjectStorage<object, int> $seen
     */
    private function encode(object $value, SplObjectStorage $seen): object
    {
        if (! self::supports($value)) {
            if ($value instanceof StreamInterface && ! self::hasSerializationContract($value)) {
                throw new LogicException('Cannot serialize notification transport stream [' . $value::class . '].');
            }

            return $value;
        }

        if ($seen->offsetExists($value)) {
            return new TransportReference($seen[$value]);
        }

        $index = count($this->nodes);
        $seen[$value] = $index;
        // Reserve the record before following members that may refer back to this object.
        $this->nodes[$index] = ['kind' => 'object', 'class' => $value::class, 'state' => []];

        if ($value::class === Stream::class) {
            $node = $this->captureStream($value);
        } else {
            /** @var array<string, mixed> $state */
            $state = (array) $value;

            if ($value instanceof Throwable) {
                $base = $value instanceof Exception ? Exception::class : Error::class;
                $trace = $value->getTrace();

                // Arguments can contain unserializable values such as SensitiveParameterValue.
                // Only the queued copy omits them; the original exception remains unchanged.
                foreach ($trace as &$frame) {
                    unset($frame['args']);
                }
                unset($frame);

                $state["\0{$base}\0trace"] = $trace;
                $state["\0{$base}\0string"] = '';
            }

            foreach ($state as $name => $member) {
                if ($value instanceof Response && $name === "\0*\0decodeUsing" && $member instanceof Closure) {
                    if (! $seen->offsetExists($member)) {
                        $closureIndex = count($this->nodes);
                        $seen[$member] = $closureIndex;
                        $this->nodes[$closureIndex] = ['kind' => 'closure', 'closure' => new SerializableClosure($member)];
                    }

                    $state[$name] = new TransportReference($seen[$member]);
                } elseif (is_object($member)) {
                    $state[$name] = $this->encode($member, $seen);
                }
            }

            $node = ['kind' => 'object', 'class' => $value::class, 'state' => $state];
        }

        $this->nodes[$index] = $node;

        return new TransportReference($index);
    }

    /**
     * Capture a default memory buffer without consuming or replacing the original stream.
     *
     * @return StreamRecord
     */
    private function captureStream(Stream $stream): array
    {
        $state = get_mangled_object_vars($stream);
        /** @var array<array-key, mixed> $customMetadata */
        $customMetadata = $state["\0" . Stream::class . "\0customMetadata"];
        /** @var null|resource $resource */
        $resource = $state["\0" . Stream::class . "\0stream"] ?? null;

        if ($resource === null) {
            return ['kind' => 'stream', 'closed' => true, 'metadata' => $customMetadata];
        }

        // Guzzle merges custom metadata over resource metadata, hiding the buffer's actual origin.
        $metadata = stream_get_meta_data($resource);

        if (! $stream->isSeekable() || ! $stream->isReadable() || ! $stream->isWritable()
            || ! in_array($metadata['uri'] ?? null, ['php://temp', 'php://memory'], true)
        ) {
            throw new LogicException('Cannot serialize notification transport bodies outside writable memory buffers.');
        }

        $position = $stream->tell();
        $eof = $stream->eof();

        try {
            $stream->rewind();
            $body = $stream->getContents();
        } finally {
            $stream->seek($position);

            if ($eof) {
                $stream->read(1);
            }
        }

        return [
            'kind' => 'stream',
            'closed' => false,
            'metadata' => $customMetadata,
            'uri' => $metadata['uri'],
            'body' => $body,
            'position' => $position,
            'eof' => $eof,
            'size' => $stream->getSize(),
        ];
    }

    /**
     * Restore the normal transport objects before invoking a queued listener.
     */
    public function restore(): object
    {
        $objects = [];

        // Allocate objects first so shared references and exception cycles retain their identity.
        foreach ($this->nodes as $index => $node) {
            if ($node['kind'] === 'object') {
                if (! is_a($node['class'], Throwable::class, true) && ! is_a($node['class'], Response::class, true)
                    && ! in_array($node['class'], [PsrRequest::class, PsrResponse::class, TransferStats::class], true)
                ) {
                    throw new LogicException('Unsupported transport object');
                }

                $objects[$index] = (new ReflectionClass($node['class']))->newInstanceWithoutConstructor();
            }
        }

        foreach ($this->nodes as $index => $node) {
            if ($node['kind'] !== 'object') {
                continue;
            }

            $object = $objects[$index];

            foreach ($node['state'] as $name => $value) {
                $scope = $object::class;

                if (str_starts_with($name, "\0")) {
                    [, $declaring, $name] = explode("\0", $name);

                    if ($declaring !== '*') {
                        $scope = $declaring;
                    }
                }

                if (property_exists($scope, $name)) {
                    (new ReflectionProperty($scope, $name))->setRawValue($object, $this->decode($value, $objects));
                } else {
                    $object->{$name} = $this->decode($value, $objects);
                }
            }
        }

        foreach ($this->nodes as $index => $node) {
            if ($node['kind'] === 'object' && method_exists($objects[$index], '__wakeup')) {
                $objects[$index]->__wakeup();
            }
        }

        return $this->decode($this->root, $objects);
    }

    /**
     * Resolve an internal reference without traversing application arrays or objects.
     *
     * @param array<int, object> $objects
     */
    private function decode(mixed $value, array &$objects): mixed
    {
        if (! $value instanceof TransportReference) {
            return $value;
        }

        if (isset($objects[$value->index])) {
            return $objects[$value->index];
        }

        $node = $this->nodes[$value->index];
        $object = match ($node['kind']) {
            'closure' => $node['closure']->getClosure(),
            'stream' => $this->restoreStream($node),
            default => throw new LogicException('Unsupported transport record'),
        };

        return $objects[$value->index] = $object;
    }

    /**
     * Restore a buffered or closed stream and release the resource if restoration fails.
     *
     * @param StreamRecord $node
     */
    private function restoreStream(array $node): Stream
    {
        if ($node['closed']) {
            $stream = new Stream(fopen('php://memory', 'w+'), ['metadata' => $node['metadata']]);
            $stream->close();

            return $stream;
        }

        if (! in_array($node['uri'], ['php://memory', 'php://temp'], true)) {
            throw new LogicException('Unsupported buffer');
        }

        $resource = fopen($node['uri'], 'w+');

        if ($resource === false) {
            throw new RuntimeException('Unable to create notification transport buffer.');
        }

        try {
            $length = strlen($node['body']);
            $offset = 0;

            while ($offset < $length) {
                $written = fwrite($resource, $offset === 0 ? $node['body'] : substr($node['body'], $offset));

                if ($written === false || $written === 0) {
                    throw new RuntimeException('Unable to restore notification transport body.');
                }

                $offset += $written;
            }

            $stream = new Stream($resource, ['metadata' => $node['metadata'], 'size' => $node['size']]);
            $stream->seek($node['position']);

            if ($node['eof']) {
                $stream->read(1);
            }

            return $stream;
        } catch (Throwable $exception) {
            fclose($resource);

            throw $exception;
        }
    }
}
