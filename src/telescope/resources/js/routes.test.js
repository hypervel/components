import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { describe, expect, it } from 'vite-plus/test';
import { createMemoryHistory, createRouter } from 'vue-router';
import routes from './routes';

/**
 * The sidebar links of the dashboard layout, e.g. "/requests".
 */
const sidebar = [
    ...readFileSync(resolve(import.meta.dirname, '../views/layout.blade.php'), 'utf8').matchAll(
        /<router-link active-class="active" to="([^"]+)"/g,
    ),
].map((match) => match[1]);

const sections = [
    ['mail', 'mail-preview'],
    ['exceptions', 'exception-preview'],
    ['logs', 'log-preview'],
    ['notifications', 'notification-preview'],
    ['jobs', 'job-preview'],
    ['batches', 'batch-preview'],
    ['events', 'event-preview'],
    ['cache', 'cache-preview'],
    ['queries', 'query-preview'],
    ['models', 'model-preview'],
    ['requests', 'request-preview'],
    ['commands', 'command-preview'],
    ['schedule', 'schedule-preview'],
    ['redis', 'redis-preview'],
    ['gates', 'gate-preview'],
    ['views', 'view-preview'],
    ['client-requests', 'client-request-preview'],
    ['reverb', 'reverb-preview'],
];

function makeRouter() {
    return createRouter({ history: createMemoryHistory(), routes });
}

/**
 * Render the sidebar links while the router shows the given path.
 */
async function activeLinksAt(path) {
    const router = makeRouter();

    await router.push(path);

    const wrapper = mount(
        {
            template: `<div>
                <router-link v-for="link in sidebar" :key="link" :to="link" active-class="active">{{ link }}</router-link>
            </div>`,
            data: () => ({ sidebar }),
        },
        { global: { plugins: [router] } },
    );

    try {
        return wrapper.findAll('a.active').map((link) => link.text());
    } finally {
        wrapper.unmount();
    }
}

describe('routes', () => {
    it('reads the sidebar links from the layout', () => {
        expect(sidebar).toContain('/requests');
        expect(sidebar.length).toBeGreaterThan(15);
    });

    it('marks only the matching sidebar link active on a list screen', async () => {
        for (const link of sidebar) {
            expect(await activeLinksAt(link)).toEqual([link]);
        }
    });

    it('keeps the list link active on a preview screen', async () => {
        const router = makeRouter();
        const withPreview = sidebar.filter((link) => router.resolve(`${link}/entry-id`).matched.length);

        expect(withPreview).toContain('/requests');

        for (const link of withPreview) {
            expect(await activeLinksAt(`${link}/entry-id`)).toEqual([link]);
        }
    });

    it('resolves the named list and preview routes', () => {
        const router = makeRouter();

        expect(router.resolve({ name: 'requests', query: { tag: 'a' } }).fullPath).toBe('/requests?tag=a');
        expect(router.resolve({ name: 'request-preview', params: { id: 'abc' } }).path).toBe('/requests/abc');
        expect(router.resolve({ name: 'dumps' }).path).toBe('/dumps');
    });

    it('resolves the named list and preview routes of every section', () => {
        const router = makeRouter();

        for (const [section, name] of sections) {
            expect(router.resolve({ name: section }).path).toBe('/' + section);
            expect(router.resolve({ name, params: { id: 'entry-id' }, query: { tag: 'Example:1' } }).fullPath).toBe(
                '/' + section + '/entry-id?tag=Example:1',
            );
        }
    });
});
