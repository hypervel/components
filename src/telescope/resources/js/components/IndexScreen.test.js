import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';
import { createMemoryHistory, createRouter, RouterView } from 'vue-router';
import Base from '../base';
import IndexScreen from './IndexScreen.vue';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));

let wrapper;
let container;

const entry = (id, status = 'pending') => ({ id, sequence: id, content: { status } });
const response = (entries) => ({ data: { entries, status: 'enabled' } });

async function mountIndex(path = '/logs', autoLoad = false) {
    const router = createRouter({
        history: createMemoryHistory(),
        routes: [
            { path: '/logs', component: IndexScreen, props: { resource: 'logs', title: 'Logs' } },
            { path: '/jobs', component: IndexScreen, props: { resource: 'jobs', title: 'Jobs' } },
            { path: '/other', component: { render: () => h('p', 'Other screen') } },
        ],
    });
    await router.push(path);
    await router.isReady();

    container = document.createElement('div');
    document.body.appendChild(container);
    wrapper = mount({
        data: () => ({ alert: {}, autoLoadsNewEntries: autoLoad }),
        render: () => h(RouterView),
    }, {
        attachTo: container,
        global: { plugins: [router], mixins: [Base] },
    });
    wrapper.vm.$root.alert = wrapper.vm.alert;
    wrapper.vm.$root.autoLoadsNewEntries = autoLoad;
    await flushPromises();

    return { router, screen: wrapper.findComponent(IndexScreen) };
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('Telescope', { basePath: '/telescope' });
    axios.post.mockReset().mockResolvedValue(response([entry('first')]));
});

afterEach(() => {
    wrapper?.unmount();
    container?.remove();
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('entry lists', () => {
    it('passes complete filter values and cancels the old load when the query changes', async () => {
        const pending = Promise.withResolvers();
        axios.post.mockReturnValueOnce(pending.promise);
        const { router, screen } = await mountIndex('/logs?tag=a%26b%23c&exact_tag=1&family_hash=family');
        const original = axios.post.mock.calls[0][2];

        expect(original.params).toEqual({ tag: 'a&b#c', exact_tag: '1', family_hash: 'family', before: '', take: 50 });
        await router.push('/logs?tag=next');
        await flushPromises();
        expect(original.signal.aborted).toBe(true);
        expect(axios.post.mock.calls[1][2].params.tag).toBe('next');
        expect(axios.post.mock.calls[1][2].params.exact_tag).toBeUndefined();

        pending.resolve(response([entry('stale')]));
        await flushPromises();
        expect(screen.vm.entries.map(entry => entry.id)).toEqual(['first']);
    });

    it('keeps literal tag matching when polling for new entries', async () => {
        await mountIndex('/logs?tag=a%2Cb&exact_tag=1');
        await vi.advanceTimersByTimeAsync(2500);
        expect(axios.post.mock.calls[1][2].params).toMatchObject({ tag: 'a,b', exact_tag: '1', take: 1 });
    });

    it('debounces search, clears literal matching and cancels a queued search when leaving the screen', async () => {
        const { router, screen } = await mountIndex('/logs?tag=a%2Cb&exact_tag=1');
        await screen.find('input').setValue('first search');
        await vi.advanceTimersByTimeAsync(499);
        expect(router.currentRoute.value.query.tag).toBe('a,b');
        expect(router.currentRoute.value.query.exact_tag).toBe('1');
        await vi.advanceTimersByTimeAsync(1);
        await flushPromises();
        expect(router.currentRoute.value.query.tag).toBe('first search');
        expect(router.currentRoute.value.query.exact_tag).toBeUndefined();
        expect(axios.post.mock.calls[1][2].params.exact_tag).toBeUndefined();

        await screen.find('input').setValue('abandoned search');
        await router.push('/other');
        const requests = axios.post.mock.calls.length;
        await vi.advanceTimersByTimeAsync(60000);
        expect(router.currentRoute.value.fullPath).toBe('/other');
        expect(axios.post).toHaveBeenCalledTimes(requests);
    });

    it.each([false, true])('respects automatic loading set to %s', async autoLoad => {
        const { screen } = await mountIndex('/logs', autoLoad);
        axios.post.mockResolvedValue(response([entry('new')]));
        await vi.advanceTimersByTimeAsync(2500);
        await flushPromises();

        expect(screen.vm.entries[0].id).toBe(autoLoad ? 'new' : 'first');
        expect(screen.vm.hasNewEntries).toBe(!autoLoad);
        expect(axios.post).toHaveBeenCalledTimes(autoLoad ? 3 : 2);
    });

    it('updates pending jobs without changing unrelated entries', async () => {
        axios.post.mockResolvedValue(response([entry('first'), entry('finished', 'processed')]));
        const { screen } = await mountIndex('/jobs');
        axios.post.mockImplementation((_url, body) => Promise.resolve(
            response(body?.uuids ? [entry('first', 'processed')] : [entry('first')])
        ));
        await vi.advanceTimersByTimeAsync(2500);

        expect(axios.post.mock.calls.find(([, body]) => body?.uuids)[1]).toEqual({ uuids: ['first'] });
        expect(screen.vm.entries.map(entry => [entry.id, entry.content.status])).toEqual([
            ['first', 'processed'], ['finished', 'processed'],
        ]);
    });

    it.each([
        ['network', new Error('offline'), true],
        ['HTTP', { response: { status: 500 } }, false],
    ])('retries a %s failure only when another request can recover', async (_type, failure, retry) => {
        axios.post.mockRejectedValueOnce(failure);
        const { screen } = await mountIndex();
        expect(screen.vm.ready).toBe(true);
        await vi.advanceTimersByTimeAsync(2500);

        expect(axios.post.mock.calls.length > 1).toBe(retry);
        expect(wrapper.vm.alert.type).toBe(retry ? undefined : 'error');
    });

    it('does not restart polling after an unmounted request completes', async () => {
        const pending = Promise.withResolvers();
        axios.post.mockReturnValueOnce(pending.promise);
        const { router } = await mountIndex();
        const signal = axios.post.mock.calls[0][2].signal;
        await router.push('/other');
        expect(signal.aborted).toBe(true);

        pending.resolve(response([entry('late')]));
        await flushPromises();
        await vi.advanceTimersByTimeAsync(60000);
        expect(axios.post).toHaveBeenCalledTimes(1);
    });

    it('focuses search only for the slash character and removes the shortcut on unmount', async () => {
        const { router, screen } = await mountIndex();
        const input = screen.find('input').element;
        document.dispatchEvent(new KeyboardEvent('keyup', { key: '?' }));
        expect(document.activeElement).not.toBe(input);
        document.dispatchEvent(new KeyboardEvent('keyup', { key: '/' }));
        expect(document.activeElement).toBe(input);

        const focus = vi.spyOn(input, 'focus');
        await router.push('/other');
        document.dispatchEvent(new KeyboardEvent('keyup', { key: '/' }));
        expect(focus).not.toHaveBeenCalled();
    });
});
