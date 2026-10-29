import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import Poll from './Poll.vue';

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    vi.restoreAllMocks();
    vi.useRealTimers();
});

it('waits for a slow callback and resumes polling after rejection', async () => {
    vi.useFakeTimers();
    const pending = Promise.withResolvers();
    const callback = vi.fn().mockReturnValueOnce(pending.promise).mockResolvedValue(undefined);
    const report = vi.spyOn(console, 'error').mockImplementation(() => {});
    wrapper = mount(Poll, { props: { callback, keepAlive: true } });

    expect(callback).toHaveBeenCalledTimes(1);
    await vi.advanceTimersByTimeAsync(9000);
    expect(callback).toHaveBeenCalledTimes(1);

    const error = new Error('Request failed');
    pending.reject(error);
    await flushPromises();
    expect(report).toHaveBeenCalledWith(error);

    await vi.advanceTimersByTimeAsync(3000);
    expect(callback).toHaveBeenCalledTimes(2);
    wrapper.unmount();
    wrapper = null;
    await vi.advanceTimersByTimeAsync(3000);
    expect(callback).toHaveBeenCalledTimes(2);
});
