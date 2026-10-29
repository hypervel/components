import { flushPromises, mount } from '@vue/test-utils';
import axios from 'axios';
import { afterEach, expect, it, vi } from 'vite-plus/test';
import Base from '../../base';
import Dumps from './index.vue';
import sfdump from './sfdump';

vi.mock('axios', () => ({ default: { post: vi.fn() } }));
vi.mock('./sfdump', () => ({ default: vi.fn() }));

let wrapper;

afterEach(() => {
    wrapper?.unmount();
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.clearAllMocks();
});

it('initializes new dump elements by ID across refreshes and stops on unmount', async () => {
    vi.useFakeTimers();
    vi.stubGlobal('Telescope', { basePath: '/telescope' });
    const renderDump = vi.fn();
    sfdump.mockReturnValue(renderDump);
    const entry = (id) => ({
        id,
        created_at: '2026-10-28T00:00:00Z',
        content: { dump: `<pre id="sf-dump-${id}">value</pre>` },
    });
    const response = (entries) => ({ data: { entries, dump: null, status: 'enabled' } });
    axios.post.mockReset().mockResolvedValue(response([entry('first')]));
    wrapper = mount(Dumps, { global: { mixins: [Base], stubs: { 'router-link': true } } });
    await flushPromises();
    expect(renderDump.mock.calls).toEqual([['sf-dump-first']]);

    axios.post.mockResolvedValue(response([entry('second'), entry('first')]));
    await vi.advanceTimersByTimeAsync(2000);
    await flushPromises();
    expect(renderDump.mock.calls).toEqual([['sf-dump-first'], ['sf-dump-second']]);
    expect(wrapper.findAll('pre').map((element) => element.attributes('id'))).toEqual([
        'sf-dump-second',
        'sf-dump-first',
    ]);

    const pending = Promise.withResolvers();
    axios.post.mockReturnValue(pending.promise);
    await vi.advanceTimersByTimeAsync(2000);
    const signal = axios.post.mock.calls.at(-1)[2].signal;
    wrapper.unmount();
    wrapper = null;
    expect(signal.aborted).toBe(true);
    pending.resolve(response([entry('late')]));
    await flushPromises();
    const requests = axios.post.mock.calls.length;
    await vi.advanceTimersByTimeAsync(10000);
    expect(axios.post).toHaveBeenCalledTimes(requests);
    expect(renderDump).toHaveBeenCalledTimes(2);
});
