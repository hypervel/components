import { mount } from '@vue/test-utils';
import { expect, it } from 'vitest';
import { h } from 'vue';
import { createMemoryHistory, createRouter, RouterLink } from 'vue-router';
import routes from './routes';

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

it('keeps the matching sidebar section active on detail pages without changing named URLs', async () => {
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/requests');
    const wrapper = mount({
        render: () => h('nav', sections.map(([section]) => h(RouterLink, {
            to: '/' + section,
            activeClass: 'active',
        }, () => section))),
    }, { global: { plugins: [router] } });

    try {
        for (const [section, name] of sections) {
            await router.push({ name, params: { id: 'entry-id' }, query: { tag: 'Example:1' } });

            expect(router.currentRoute.value.path).toBe('/' + section + '/entry-id');
            expect(wrapper.findAll('a.active').map(link => link.text())).toEqual([section]);
            expect(router.resolve({ name: section }).path).toBe('/' + section);
        }
    } finally {
        wrapper.unmount();
    }
});
