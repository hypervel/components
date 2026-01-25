<?php

declare(strict_types=1);

namespace Hypervel\Encryption;

use Hypervel\Encryption\Commands\KeyGenerateCommand;
use Hypervel\Contracts\Encryption\Encrypter;

class ConfigProvider
{
    public function __invoke(): array
    {
        return [
            'dependencies' => [
                Encrypter::class => EncryptionFactory::class,
            ],
            'commands' => [
                KeyGenerateCommand::class,
            ],
            'publish' => [
                [
                    'id' => 'config',
                    'description' => 'The configuration file of encryption.',
                    'source' => __DIR__ . '/../publish/encryption.php',
                    'destination' => BASE_PATH . '/config/autoload/encryption.php',
                ],
            ],
        ];
    }
}
