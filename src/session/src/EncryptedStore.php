<?php

declare(strict_types=1);

namespace Hypervel\Session;

use Hypervel\Encryption\Contracts\Encrypter as EncrypterContract;
use Hypervel\Encryption\Exceptions\DecryptException;
use SessionHandlerInterface;

class EncryptedStore extends Store
{
    /**
     * The encrypter instance.
     */
    protected EncrypterContract $encrypter;

    /**
     * Create a new session instance.
     */
    public function __construct(string $name, SessionHandlerInterface $handler, EncrypterContract $encrypter, string $serialization = 'php')
    {
        $this->encrypter = $encrypter;

        parent::__construct($name, $handler, $serialization);
    }

    /**
     * Prepare the raw string data from the session for unserialization.
     */
    protected function prepareForUnserialize(string $data): string
    {
        try {
            return $this->encrypter->decrypt($data);
        } catch (DecryptException) {
            return $this->serialization === 'json' ? json_encode([]) : serialize([]);
        }
    }

    /**
     * Prepare the serialized session data for storage.
     */
    protected function prepareForStorage(string $data): string
    {
        return $this->encrypter->encrypt($data);
    }

    /**
     * Get the encrypter instance.
     */
    public function getEncrypter(): EncrypterContract
    {
        return $this->encrypter;
    }
}
