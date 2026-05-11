import { execSync } from 'node:child_process';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const generated = resolve(here, '.generated');

export function setup(): void {
    try {
        execSync(`php ${here}/generate.php ${generated}`, { stdio: 'inherit' });
    } catch {
        console.error('Wayfinder fixture generation failed');
        process.exit(1);
    }
}

export function teardown(): void {
    // Leave .generated/ in place — gitignored, useful for inspecting output.
}
