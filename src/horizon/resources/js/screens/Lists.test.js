import { flushPromises, shallowMount } from '@vue/test-utils';
import { afterEach, beforeEach, expect, it, vi } from 'vite-plus/test';
import Base from '../base';
import Poll from '../components/Poll.vue';
import RecentJobs from './recentJobs/index.vue';
import FailedJobs from './failedJobs/index.vue';
import MonitoredJobs from './monitoring/tag-jobs.vue';
import Batches from './batches/index.vue';

let wrapper;
const http = { get: vi.fn() };
const router = { replace: vi.fn().mockResolvedValue(undefined) };

function response(key, prefix) {
    return {
        data: {
            [key]: Array.from({ length: 50 }, (_, index) => ({
                id: `${prefix}-${index}`,
                name: prefix,
                payload: {},
                retried_by: [],
                createdAt: '2026-01-01',
            })),
            total: 100,
        },
    };
}

function mountList(component) {
    wrapper = shallowMount(component, {
        props: component === MonitoredJobs ? { type: 'jobs' } : {},
        global: {
            components: { poll: Poll },
            stubs: { poll: false, 'router-link': true },
            mixins: [Base, { data: () => ({ autoLoadsNewEntries: true }) }],
            mocks: { $http: http, $router: router, $route: { params: { type: 'pending', tag: 'example' }, query: {} } },
        },
    });
}

beforeEach(() => {
    vi.useFakeTimers();
    vi.stubGlobal('Horizon', { basePath: '/horizon' });
    vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible');
    http.get.mockReset();
    router.replace.mockClear();
});

afterEach(() => {
    wrapper?.unmount();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

it.each([
    ['recent jobs', RecentJobs, 'jobs'],
    ['failed jobs', FailedJobs, 'jobs'],
    ['monitored jobs', MonitoredJobs, 'jobs'],
    ['batches', Batches, 'batches'],
])('%s waits for slow loads and ignores a superseded refresh', async (_, component, key) => {
    const initial = Promise.withResolvers();
    http.get.mockReturnValue(initial.promise);
    mountList(component);
    expect(http.get).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(3000);
    expect(http.get).toHaveBeenCalledTimes(1);
    initial.resolve(response(key, 'initial'));
    await flushPromises();

    const old = Promise.withResolvers();
    const current = Promise.withResolvers();
    http.get.mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise);
    await vi.advanceTimersByTimeAsync(3000);
    const signal = http.get.mock.calls[1][1].signal;
    await wrapper.findAll('button').at(-1).trigger('click');
    expect(signal.aborted).toBe(true);
    expect(http.get).toHaveBeenCalledTimes(3);
    await vi.advanceTimersByTimeAsync(3000);
    expect(http.get).toHaveBeenCalledTimes(3);

    current.resolve(response(key, 'current'));
    await flushPromises();
    old.resolve(response(key, 'stale'));
    await flushPromises();
    expect(wrapper.vm[key][0].id).toBe('current-0');

    const leaving = Promise.withResolvers();
    http.get.mockReturnValueOnce(leaving.promise);
    wrapper.vm.loadNewEntries();
    const leavingSignal = http.get.mock.calls.at(-1)[1].signal;
    wrapper.unmount();
    wrapper = null;
    expect(leavingSignal.aborted).toBe(true);
    leaving.reject(new Error('canceled'));
    await flushPromises();
});

it('keeps failed-job cursors consistent on the first page, next page and refresh', async () => {
    http.get.mockResolvedValue(response('jobs', 'job'));
    mountList(FailedJobs);
    await flushPromises();
    expect(http.get.mock.calls[0][1].params.starting_at).toBe(-1);
    await wrapper.findAll('button').at(-1).trigger('click');
    await flushPromises();
    expect(http.get.mock.calls[1][1].params.starting_at).toBe(49);
    await vi.advanceTimersByTimeAsync(3000);
    expect(http.get.mock.calls[2][1].params.starting_at).toBe(49);
});

it('searches failed tags once from page one and cancels a queued search when leaving', async () => {
    http.get.mockResolvedValue(response('jobs', 'job'));
    mountList(FailedJobs);
    await flushPromises();
    await wrapper.findAll('button').at(-1).trigger('click');
    await flushPromises();
    http.get.mockClear();
    await wrapper.find('input').setValue('queue:a+b&c#d');
    await vi.advanceTimersByTimeAsync(500);
    expect(http.get).toHaveBeenCalledTimes(1);
    expect(http.get.mock.calls[0][1].params).toEqual({ tag: 'queue:a+b&c#d', starting_at: -1 });
    expect(wrapper.vm.page).toBe(1);
    await wrapper.find('input').setValue('abandoned');
    wrapper.unmount();
    wrapper = null;
    await vi.advanceTimersByTimeAsync(500);
    expect(http.get).toHaveBeenCalledTimes(1);
});

it('does not write batch search parameters onto the next screen after leaving', async () => {
    http.get.mockResolvedValue(response('batches', 'batch'));
    mountList(Batches);
    await flushPromises();
    await wrapper.find('input').setValue('abandoned');
    wrapper.unmount();
    wrapper = null;
    await vi.advanceTimersByTimeAsync(500);
    expect(router.replace).not.toHaveBeenCalled();
    expect(http.get).toHaveBeenCalledTimes(1);
});
