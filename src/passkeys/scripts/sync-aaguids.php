<?php

declare(strict_types=1);

/**
 * Publish the generated AAGUID catalogue atomically.
 */
function publishAaguids(string $destination, string $contents): void
{
    $temporary = @tempnam(dirname($destination), '.aaguids-');

    if ($temporary === false) {
        throw new RuntimeException('Unable to create a temporary AAGUID catalogue.');
    }

    try {
        if (@file_put_contents($temporary, $contents) !== strlen($contents)) {
            throw new RuntimeException('Unable to write the complete AAGUID catalogue.');
        }

        // tempnam() creates the file with 0600; publish it with the repository's normal file mode.
        if (! @chmod($temporary, 0644)) {
            throw new RuntimeException('Unable to set permissions on the AAGUID catalogue.');
        }

        if (! @rename($temporary, $destination)) {
            throw new RuntimeException('Unable to publish the AAGUID catalogue.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__) {
    return;
}

/**
 * Sync the AAGUID list from the passkey-authenticator-aaguids repository.
 *
 * @see https://github.com/passkeydeveloper/passkey-authenticator-aaguids
 */
$source = 'https://raw.githubusercontent.com/passkeydeveloper/passkey-authenticator-aaguids/main/aaguid.json';
$destination = __DIR__ . '/../resources/aaguids.php';

$json = @file_get_contents($source);

if ($json === false) {
    fwrite(STDERR, "Failed to fetch AAGUID list from {$source}\n");
    exit(1);
}

$data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

if (! is_array($data)) {
    fwrite(STDERR, "AAGUID list did not decode to an array.\n");
    exit(1);
}

$aaguids = [];

foreach ($data as $aaguid => $entry) {
    if (! is_string($aaguid) || $aaguid === ''
        || ! is_array($entry)
        || ! is_string($entry['name'] ?? null)
        || $entry['name'] === '') {
        fwrite(STDERR, "AAGUID list contains an invalid entry.\n");
        exit(1);
    }

    $aaguids[$aaguid] = $entry['name'];
}

$exported = var_export($aaguids, true);
$exported = str_replace("\n  ", "\n    ", $exported);
$exported = substr_replace($exported, '[', 0, strlen('array ('));
$exported = substr_replace($exported, ']', -1);

publishAaguids($destination, "<?php\n\ndeclare(strict_types=1);\n\nreturn {$exported};\n");

echo 'Synced ' . count($aaguids) . " AAGUIDs.\n";
