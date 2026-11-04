import { flushPromises, mount } from '@vue/test-utils';
import { Dropdown } from 'bootstrap';
import { afterEach, expect, it } from 'vite-plus/test';
import { createRouter, createWebHistory } from 'vue-router';
import Base from '../base';
import routes from '../routes';
import RelatedEntries from './RelatedEntries.vue';

const contents = {
    exception: { class: 'RuntimeException', message: 'Failed' },
    log: { message: 'Message', level: 'info' },
    view: { name: 'home', path: '/views/home.blade.php', composers: [] },
    query: { sql: 'select 1', time: 1, hash: 'query', connection: 'testing' },
    model: { model: 'User', action: 'retrieved', count: 1 },
    gate: { ability: 'view', result: 'allowed' },
    job: { name: 'Job', status: 'processed', connection: 'sync', queue: 'default' },
    mail: { mailable: 'Welcome', subject: 'Welcome' },
    notification: { notification: 'Notice', notifiable: 'User', channel: 'mail' },
    event: { name: 'Event', listeners: [] },
    cache: { key: 'key', type: 'hit' },
    redis: { command: 'GET key', time: 1 },
    client_request: { method: 'GET', uri: 'https://example.test', response_status: 200, duration: 1 },
};
const batch = Object.entries(contents).map(([type, content]) => ({
    id: type,
    type,
    content,
    created_at: '2026-10-28T00:00:00Z',
}));

let wrapper;
let container;

function telescopeRouter() {
    return createRouter({ history: createWebHistory('/telescope/'), routes });
}

function mountRelated(router) {
    container = document.createElement('div');
    document.body.appendChild(container);
    wrapper = mount(RelatedEntries, {
        attachTo: container,
        props: { entry: {}, batch },
        global: { mixins: [Base], plugins: [router] },
    });
}

afterEach(() => {
    wrapper?.unmount();
    container?.remove();
    window.history.replaceState(null, '', '/');
});

it('makes every related entry type reachable and preserves router history state', async () => {
    const router = telescopeRouter();
    await router.push('/requests/previous-id');
    await router.push('/requests/request-id');
    const state = window.history.state;
    mountRelated(router);

    expect(wrapper.findAll('.nav-item:not(.dropdown)')).toHaveLength(7);
    expect(wrapper.findAll('.dropdown-item').map((link) => link.text())).toEqual([
        'Mail (1)',
        'Notifications (1)',
        'Events (1)',
        'Cache (1)',
        'Redis (1)',
        'HTTP Client (1)',
    ]);
    await wrapper.findAll('.dropdown-item').at(-1).trigger('click');
    await flushPromises();
    expect(window.location.hash).toBe('#client_requests');
    expect(window.history.state).toMatchObject({
        back: state.back,
        current: '/requests/request-id#client_requests',
        position: state.position,
    });
    expect(wrapper.find('.dropdown-toggle').classes()).toContain('active');
});

it('restores the selected tab when going back to the entry', async () => {
    const router = telescopeRouter();
    const mountForRouter = () =>
        mount(RelatedEntries, {
            props: {
                entry: { id: 'request-id', content: {} },
                batch: batch.filter(({ type }) => ['log', 'query'].includes(type)),
            },
            global: { plugins: [router], mixins: [Base] },
        });
    const tab = (mounted, title) => mounted.findAll('.nav-pills a').find((link) => link.text().startsWith(title));

    await router.push('/requests/request-id');

    wrapper = mountForRouter();
    await flushPromises();

    expect(tab(wrapper, 'Logs').classes()).toContain('active');

    await tab(wrapper, 'Queries').trigger('click');
    await flushPromises();

    expect(window.location.hash).toBe('#queries');

    await router.push('/queries/query-id');

    const returned = new Promise((resolve) => window.addEventListener('popstate', resolve, { once: true }));
    window.history.back();
    await returned;
    await flushPromises();

    expect(window.location.pathname + window.location.hash).toBe('/telescope/requests/request-id#queries');

    wrapper.unmount();
    wrapper = mountForRouter();
    await flushPromises();

    expect(tab(wrapper, 'Queries').classes()).toContain('active');
});

it('releases the dropdown when changing entries removes the overflow tabs', async () => {
    mountRelated(telescopeRouter());
    const element = wrapper.find('.dropdown-toggle').element;
    expect(Dropdown.getInstance(element)).not.toBeNull();
    await wrapper.setProps({ entry: { id: 'next' }, batch: batch.slice(0, 1) });

    expect(wrapper.find('.dropdown-toggle').exists()).toBe(false);
    expect(Dropdown.getInstance(element)).toBeNull();
});
