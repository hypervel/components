import { mount } from '@vue/test-utils';
import { Dropdown } from 'bootstrap';
import { afterEach, expect, it } from 'vitest';
import Base from '../base';
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
    id: type, type, content, created_at: '2026-10-28T00:00:00Z',
}));

let wrapper;
let container;

function mountRelated() {
    container = document.createElement('div');
    document.body.appendChild(container);
    wrapper = mount(RelatedEntries, {
        attachTo: container,
        props: { entry: {}, batch },
        global: { mixins: [Base], stubs: { 'router-link': true } },
    });
}

afterEach(() => {
    wrapper?.unmount();
    container?.remove();
    window.history.replaceState(null, '', '/');
});

it('makes every related entry type reachable and preserves router history state', async () => {
    const state = { back: '/previous', current: '/entry', forward: null, position: 3, scroll: null };
    window.history.replaceState(state, '', '/entry');
    mountRelated();

    expect(wrapper.findAll('.nav-item:not(.dropdown)')).toHaveLength(7);
    expect(wrapper.findAll('.dropdown-item').map(link => link.text())).toEqual([
        'Mail (1)', 'Notifications (1)', 'Events (1)', 'Cache (1)', 'Redis (1)', 'HTTP Client (1)',
    ]);
    await wrapper.findAll('.dropdown-item').at(-1).trigger('click');
    expect(window.location.hash).toBe('#client_requests');
    expect(window.history.state).toEqual(state);
    expect(wrapper.find('.dropdown-toggle').classes()).toContain('active');
});

it('releases the dropdown when changing entries removes the overflow tabs', async () => {
    mountRelated();
    const element = wrapper.find('.dropdown-toggle').element;
    expect(Dropdown.getInstance(element)).not.toBeNull();
    await wrapper.setProps({ entry: { id: 'next' }, batch: batch.slice(0, 1) });

    expect(wrapper.find('.dropdown-toggle').exists()).toBe(false);
    expect(Dropdown.getInstance(element)).toBeNull();
});
