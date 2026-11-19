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
$namesDestination = __DIR__ . '/../resources/aaguids.php';
$iconsDestination = __DIR__ . '/../resources/aaguid-icons.php';

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

$names = [];
$icons = [];

foreach ($data as $aaguid => $entry) {
    if (! is_string($aaguid) || $aaguid === ''
        || ! is_array($entry)
        || ! is_string($entry['name'] ?? null)
        || $entry['name'] === '') {
        fwrite(STDERR, "AAGUID list contains an invalid entry.\n");
        exit(1);
    }

    $names[$aaguid] = $entry['name'];

    foreach (['light' => 'icon_light', 'dark' => 'icon_dark'] as $theme => $key) {
        if (! array_key_exists($key, $entry)) {
            continue;
        }

        // Applications render icons as image sources, so keep the list's documented SVG data URI form.
        if (! is_string($entry[$key]) || ! str_starts_with($entry[$key], 'data:image/svg+xml;base64,')) {
            fwrite(STDERR, "AAGUID list contains an invalid entry.\n");
            exit(1);
        }

        $icons[$aaguid][$theme] = $entry[$key];
    }
}

/**
 * Render a flat `key => scalar` array as a PHP file.
 *
 * @param array<string, string> $values
 */
$renderNames = function (array $values): string {
    $lines = ['<?php', '', 'declare(strict_types=1);', '', 'return ['];

    foreach ($values as $aaguid => $name) {
        $lines[] = sprintf('    %s => %s,', var_export($aaguid, true), var_export($name, true));
    }

    return implode("\n", [...$lines, '];', '']);
};

/**
 * Render a nested `key => [theme => icon]` array as a PHP file.
 *
 * @param array<string, array<string, string>> $values
 */
$renderIcons = function (array $values): string {
    $lines = ['<?php', '', 'declare(strict_types=1);', '', 'return ['];

    foreach ($values as $aaguid => $themes) {
        $lines[] = sprintf('    %s => [', var_export($aaguid, true));
        foreach ($themes as $theme => $icon) {
            $lines[] = sprintf('        %s => %s,', var_export($theme, true), var_export($icon, true));
        }
        $lines[] = '    ],';
    }

    return implode("\n", [...$lines, '];', '']);
};

publishAaguids($namesDestination, $renderNames($names));
publishAaguids($iconsDestination, $renderIcons($icons));

echo 'Synced ' . count($names) . ' AAGUIDs and ' . count($icons) . " icon sets.\n";
