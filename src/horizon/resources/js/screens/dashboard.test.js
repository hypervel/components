import { flushPromises, shallowMount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import Dashboard from './dashboard.vue';

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    vi.unstubAllGlobals();
});

it('waits for every dashboard request before reporting a partial failure', async () => {
    vi.stubGlobal('Horizon', { basePath: '/horizon' });
    const error = new Error('Stats unavailable');
    const workers = Promise.withResolvers();
    const workload = Promise.withResolvers();
    const get = vi
        .fn()
        .mockRejectedValueOnce(error)
        .mockReturnValueOnce(workers.promise)
        .mockReturnValueOnce(workload.promise);
    wrapper = shallowMount(Dashboard, { global: { mocks: { $http: { get } }, stubs: { poll: true } } });
    const settled = vi.fn();
    const request = wrapper.vm.refreshStatsPeriodically().then(settled, settled);
    await flushPromises();
    expect(settled).not.toHaveBeenCalled();
    workers.resolve({ data: [] });
    await flushPromises();
    expect(settled).not.toHaveBeenCalled();
    workload.resolve({ data: [] });
    await request;
    expect(settled).toHaveBeenCalledWith(error);
    expect(wrapper.vm.ready).toBe(false);
});
